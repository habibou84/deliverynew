<?php

namespace App\Jobs;

use App\Services\Live\LiveWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Message d'une cliente reçu sur le WhatsApp de l'e-commerçante pendant un live */
class ProcessLiveWhatsApp implements ShouldQueue
{
    use Queueable;

    // Jamais deux fois (commande ou réponse en double)
    public int $tries = 1;

    public function __construct(public int $merchantId, public string $from, public ?string $name, public ?string $text)
    {
        $this->onQueue('messages');
    }

    public function handle(LiveWhatsApp $live): void
    {
        $live->onMessage($this->merchantId, $this->from, $this->name, $this->text);
    }
}
