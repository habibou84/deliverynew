<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Réponse automatique à un client du live, depuis le WhatsApp de l'e-commerçante : par la connexion officielle
 * (numéro relié à Meta), sinon par le service whatsapp-bridge (code d'association / QR code).
 * Avec une photo (image) : la photo et le texte en légende par la connexion officielle ; le texte seul sinon.
 * Avec des choix (connexion officielle) : 3 boutons au plus, sinon une liste à choisir ; la réponse touchée revient
 * avec l'identifiant du choix (« 1 », « 2 »…), comme si le client l'avait tapé.
 */
class SendLiveWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $session, public string $to, public string $text, public ?int $accountId = null, public ?string $image = null, public array $choices = [], public ?string $prompt = null)
    {
        $this->onQueue('messages');
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(WhatsAppBridge $bridge, MetaCloudGateway $meta): void
    {
        try {
            $account = $this->accountId ? WhatsAppAccount::withoutGlobalScopes()->find($this->accountId) : null;
            if ($account !== null && $this->choices !== []) {
                $this->sendChoices($meta, $account);

                return;
            }
            match (true) {
                $account !== null && $this->image !== null => $meta->sendImage($account, $this->to, $this->image, $this->text),
                $account !== null => $meta->sendReply($account, $this->to, $this->text),
                default => $this->text !== '' ? $bridge->send($this->session, $this->to, $this->text) : null,
            };
        } catch (MessagingException $e) {
            if ($e->retryable) {
                throw $e;
            }
            Log::warning('Réponse WhatsApp du live non envoyée', ['session' => $this->session, 'error' => $e->getMessage()]);
        }
    }

    /** Le texte (ou la photo et sa légende), puis les boutons ou la liste */
    private function sendChoices(MetaCloudGateway $meta, WhatsAppAccount $account): void
    {
        $body = $this->text;
        // Une photo ou un long texte ne tient pas dans le message à choix : envoyés d'abord
        if ($this->image !== null || mb_strlen($body) > 1024) {
            $this->image !== null
                ? $meta->sendImage($account, $this->to, $this->image, $body)
                : $meta->sendReply($account, $this->to, $body);
            $body = $this->prompt ?? '👉 Que voulez-vous faire ?';
        }
        $buttons = collect($this->choices)->mapWithKeys(fn ($c) => [(string) $c['id'] => $c['title']]);
        $fits = count($this->choices) <= 3 && $buttons->every(fn ($t) => mb_strlen($t) <= 20)
            && $buttons->unique()->count() === count($this->choices);

        $fits
            ? $meta->sendReply($account, $this->to, $body, $buttons->all())
            : $meta->sendList($account, $this->to, $body, 'Choisir', $this->choices);
    }
}
