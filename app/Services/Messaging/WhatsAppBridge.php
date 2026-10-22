<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Service whatsapp-bridge (dossier whatsapp-bridge, sur le serveur) : connexion WhatsApp par QR code, une session par
 * entreprise. Démarrer la session et lire son QR code, envoyer un message (mis en file, réponse immédiate), se
 * déconnecter. Les événements du service arrivent sur WhatsAppBridgeWebhookController.
 */
class WhatsAppBridge
{
    public static function configured(): bool
    {
        return filled(config('messaging.whatsapp.bridge.token'));
    }

    /**
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     *
     * @throws MessagingException
     */
    public function start(WhatsAppAccount $account): array
    {
        return $this->describe($this->call(fn () => $this->http(30)->post('/sessions/'.$account->bridgeSession().'/start')));
    }

    /**
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     *
     * @throws MessagingException
     */
    public function status(WhatsAppAccount $account): array
    {
        return $this->describe($this->call(fn () => $this->http()->get('/sessions/'.$account->bridgeSession())));
    }

    /** @throws MessagingException */
    public function logout(WhatsAppAccount $account): void
    {
        $this->call(fn () => $this->http(30)->post('/sessions/'.$account->bridgeSession().'/logout'));
    }

    /**
     * Message mis dans la file du service ; son envoi réel suit (événement « sent » ou « failed »).
     *
     * @throws MessagingException
     */
    public function send(WhatsAppAccount $account, string $to, string $text): string
    {
        $response = $this->call(fn () => $this->http()->post('/sessions/'.$account->bridgeSession().'/send', ['to' => $to, 'text' => $text]));

        return (string) $response->json('id');
    }

    private function http(int $timeout = 15): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('messaging.whatsapp.bridge.url'), '/'))
            ->withToken((string) config('messaging.whatsapp.bridge.token'))
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws MessagingException
     */
    private function call(callable $request): Response
    {
        if (! self::configured()) {
            throw new MessagingException('Connexion par QR code indisponible : le service WhatsApp n\'est pas installé sur le serveur.');
        }

        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new MessagingException('Le service WhatsApp du serveur ne répond pas. Réessayez dans un instant.', retryable: true);
        }

        if ($response->failed()) {
            throw new MessagingException($response->json('message') ?? 'Erreur du service WhatsApp ('.$response->status().').', retryable: $response->serverError());
        }

        return $response;
    }

    /**
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     */
    private function describe(Response $response): array
    {
        return [
            'status' => (string) ($response->json('status') ?? 'logged_out'),
            'qr' => $response->json('qr'),
            'phone' => $response->json('phone'),
            'name' => $response->json('name'),
        ];
    }
}
