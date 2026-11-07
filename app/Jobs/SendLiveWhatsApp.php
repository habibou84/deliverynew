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
 */
class SendLiveWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $session, public string $to, public string $text, public ?int $accountId = null, public ?string $image = null)
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
}
