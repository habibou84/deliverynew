<?php

namespace App\Mail;

use App\Services\Platform\GmailConnection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

/**
 * Pilote d'envoi « gmail » : le message est envoyé par l'API de Gmail (HTTPS), avec l'autorisation donnée par la
 * connexion Google de la console. Ni serveur SMTP, ni mot de passe d'application.
 */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(private readonly GmailConnection $gmail)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $response = Http::withToken($this->gmail->accessToken())->timeout(20)->post(GmailConnection::SEND_URL, [
                'raw' => rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '='),
            ]);
        } catch (Throwable $e) {
            // Autorisation retirée : le message « reconnectez Gmail » est déjà noté
            if ($this->gmail->connected()) {
                $this->gmail->recordError($e->getMessage());
            }

            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $error = 'Gmail a refusé l\'envoi : '.($response->json('error.message') ?? 'erreur '.$response->status());
            $this->gmail->recordError($error);

            throw new TransportException($error);
        }
        $this->gmail->clearError();
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
