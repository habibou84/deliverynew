<?php

namespace App\Jobs;

use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Réponse automatique à une cliente du live, envoyée depuis le WhatsApp relié de l'e-commerçante */
class SendLiveWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $session, public string $to, public string $text)
    {
        $this->onQueue('messages');
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(WhatsAppBridge $bridge): void
    {
        try {
            $bridge->send($this->session, $this->to, $this->text);
        } catch (MessagingException $e) {
            if ($e->retryable) {
                throw $e;
            }
            Log::warning('Réponse WhatsApp du live non envoyée', ['session' => $this->session, 'error' => $e->getMessage()]);
        }
    }
}
