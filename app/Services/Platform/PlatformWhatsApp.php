<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\PlatformConversation;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Numéro WhatsApp de Jibiat : messagerie de la console. Deux connexions possibles : officielle (API Cloud de Meta,
 * saisie dans la console, compte « platform ») ou par QR code (session « platform » du service whatsapp-bridge).
 * L'officielle, une fois vérifiée, est utilisée en priorité. Les messages reçus ouvrent ou complètent une
 * conversation ; la console y répond depuis le même numéro. L'état du QR code est gardé dans les réglages de la plateforme.
 */
class PlatformWhatsApp
{
    public const SESSION = 'platform';

    public function __construct(
        private readonly WhatsAppBridge $bridge,
        private readonly MetaCloudGateway $meta,
    ) {}

    /** Numéro officiel vérifié auprès de Meta (envois réels) */
    public function official(): ?WhatsAppAccount
    {
        $account = WhatsAppAccount::platform();

        return $account?->isLive() ? $account : null;
    }

    /**
     * Connexion utilisée : l'officielle si elle est vérifiée, sinon le QR code.
     *
     * @return array{status: string, phone: ?string, name: ?string, connected_at: ?string, error: ?string, channel: string}
     */
    public function state(): array
    {
        $official = $this->official();
        if ($official !== null) {
            return [
                'status' => 'connected',
                'phone' => PhoneNumber::normalize($official->display_phone) ?? $official->display_phone,
                'name' => $official->verified_name,
                'connected_at' => $official->connected_at?->toIso8601String(),
                'error' => null,
                'channel' => 'official',
            ];
        }

        return [...$this->qrState(), 'channel' => 'qr'];
    }

    /**
     * @return array{status: string, phone: ?string, name: ?string, connected_at: ?string, error: ?string}
     */
    public function qrState(): array
    {
        return [
            'status' => PlatformSetting::get('whatsapp.status', 'none'),
            'phone' => PlatformSetting::get('whatsapp.phone'),
            'name' => PlatformSetting::get('whatsapp.name'),
            'connected_at' => PlatformSetting::get('whatsapp.connected_at'),
            'error' => PlatformSetting::get('whatsapp.error'),
        ];
    }

    public function connected(): bool
    {
        return $this->state()['status'] === 'connected';
    }

    /**
     * Événement du service pour la session de la plateforme.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        match ((string) ($event['type'] ?? '')) {
            'status' => $this->status((string) ($event['status'] ?? ''), $event),
            'message' => $this->receive($event),
            'sent', 'delivered', 'read' => $this->advance((string) ($event['id'] ?? ''), (string) $event['type']),
            'failed' => $this->advance((string) ($event['id'] ?? ''), 'failed', isset($event['error']) ? (string) $event['error'] : null),
            default => null,
        };
    }

    public function markPending(): void
    {
        if ($this->qrState()['status'] !== 'connected') {
            PlatformSetting::put('whatsapp.status', 'pending');
        }
    }

    public function markDisconnected(?string $error = null): void
    {
        PlatformSetting::put('whatsapp.status', 'disconnected');
        PlatformSetting::put('whatsapp.error', $error);
    }

    /**
     * Réponse de la console : enregistrée puis confiée au service (envoi espacé, accusés par événements).
     *
     * @throws MessagingException
     */
    public function reply(PlatformConversation $conversation, string $body, User $by): PlatformMessage
    {
        if (! $this->connected()) {
            throw new MessagingException('Le numéro WhatsApp de Jibiat n\'est pas relié : renseignez la connexion officielle ou scannez le QR code.');
        }

        $message = $conversation->messages()->create(['direction' => PlatformMessage::OUT, 'body' => $body, 'status' => 'queued', 'user_id' => $by->id]);
        try {
            $id = $this->send($conversation->phone, $body);
            $message->forceFill(['provider_message_id' => $id ?: null, 'status' => 'sent'])->save();
        } catch (MessagingException $e) {
            $message->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }
        $conversation->forceFill(['last_message_at' => now(), 'last_message' => Str::limit($body, 190), 'unread' => 0])->save();

        return $message;
    }

    /**
     * Texte libre depuis le numéro de Jibiat, par la connexion utilisée. Avec la connexion officielle, Meta ne
     * l'accepte que dans les 24 heures qui suivent le dernier message de la personne.
     *
     * @throws MessagingException
     */
    private function send(string $phone, string $text): string
    {
        $official = $this->official();
        if ($official === null) {
            return $this->bridge->send(self::SESSION, $phone, $text);
        }

        try {
            return $this->meta->sendReply($official, $phone, $text);
        } catch (MessagingException $e) {
            throw new MessagingException($e->providerCode === '131047'
                ? 'Plus de 24 heures depuis son dernier message : WhatsApp n\'accepte plus de message libre. Attendez qu\'il vous écrive.'
                : $e->getMessage(), providerCode: $e->providerCode);
        }
    }

    /**
     * Message reçu sur le numéro officiel (webhook de Meta), mis au format des événements du service QR code.
     *
     * @param  array<string, mixed>  $message
     */
    public function receiveFromMeta(array $message, ?string $name = null): void
    {
        $type = (string) ($message['type'] ?? 'other');
        $this->receive([
            'id' => $message['id'] ?? '',
            'from' => '+'.ltrim((string) ($message['from'] ?? ''), '+'),
            'name' => $name,
            'text' => match ($type) {
                'text' => $message['text']['body'] ?? null,
                'button' => $message['button']['text'] ?? null,
                'interactive' => $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null,
                default => null,
            },
            'kind' => $type,
        ]);
    }

    /** Accusé de Meta pour un message de la console */
    public function acknowledge(string $id, string $status, ?string $error = null): bool
    {
        $message = $id === '' ? null : PlatformMessage::query()->where('provider_message_id', $id)->first();
        $message?->advanceTo($status, $error);

        return $message !== null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function status(string $status, array $event): void
    {
        match ($status) {
            'connected' => collect([
                'whatsapp.status' => 'connected',
                'whatsapp.phone' => $event['phone'] ?? null,
                'whatsapp.name' => $event['name'] ?? null,
                'whatsapp.connected_at' => now()->toIso8601String(),
                'whatsapp.error' => null,
            ])->each(fn ($value, $key) => PlatformSetting::put($key, $value)),
            'logged_out' => $this->markDisconnected($event['error'] ?? 'Téléphone déconnecté : scannez un nouveau QR code.'),
            'disconnected' => PlatformSetting::put('whatsapp.error', $event['error'] ?? null),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function receive(array $event): void
    {
        $id = (string) ($event['id'] ?? '');
        $phone = PhoneNumber::normalize((string) ($event['from'] ?? '')) ?? (string) ($event['from'] ?? '');
        if ($id === '' || $phone === '' || PlatformMessage::query()->where('provider_message_id', $id)->exists()) {
            return;
        }

        $text = $event['text'] ?? null;
        // Code de vérification d'un numéro (inscription, mot de passe oublié) : réponse immédiate, hors messagerie
        if (is_string($text) && ($answer = app(PhoneVerifier::class)->handlePlatformWhatsApp($phone, $text)) !== null) {
            try {
                $this->send($phone, $answer);
            } catch (MessagingException) {
                // Service injoignable : la vérification est faite, la réponse manquera seulement
            }

            return;
        }
        $body = $text !== null && $text !== '' ? (string) $text : match ($event['kind'] ?? 'other') {
            'image' => '📷 Photo (à voir sur le téléphone)',
            'audio' => '🎤 Message vocal (à écouter sur le téléphone)',
            'document' => '📄 Document (à voir sur le téléphone)',
            'location' => '📍 Position (à voir sur le téléphone)',
            default => '💬 Message (à voir sur le téléphone)',
        };

        $conversation = PlatformConversation::query()->firstOrNew(['phone' => $phone]);
        $conversation->name = $conversation->name ?: ($event['name'] ?? null);
        $conversation->company_id ??= $this->companyFor($phone);
        $conversation->last_message_at = now();
        $conversation->last_message = Str::limit($body, 190);
        $conversation->unread = (int) $conversation->unread + 1;
        $conversation->save();

        $conversation->messages()->create(['direction' => PlatformMessage::IN, 'body' => $body, 'status' => 'received', 'provider_message_id' => $id]);
    }

    private function advance(string $id, string $status, ?string $error = null): void
    {
        $this->acknowledge($id, $status, $error);
    }

    /** Entreprise du numéro : un compte de son équipe, sinon le numéro de l'entreprise */
    private function companyFor(string $phone): ?int
    {
        return User::withoutGlobalScopes()->where('phone', $phone)->whereNull('merchant_id')->whereNotNull('company_id')->value('company_id')
            ?? Company::withoutGlobalScopes()->where('phone', $phone)->value('id');
    }
}
