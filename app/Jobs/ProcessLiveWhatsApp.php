<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Services\Live\LiveWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Message d'une cliente reçu sur le WhatsApp de l'e-commerçante pendant un live : par la connexion officielle
 * ($id = son numéro relié à Meta), sinon par code d'association / QR code ($id = l'e-commerçante).
 */
class ProcessLiveWhatsApp implements ShouldQueue
{
    use Queueable;

    // Jamais deux fois (commande ou réponse en double)
    public int $tries = 1;

    public function __construct(public int $merchantId, public string $from, public ?string $name, public ?string $text, public bool $official = false)
    {
        $this->onQueue('messages');
    }

    public function handle(LiveWhatsApp $live): void
    {
        if (! $this->official) {
            $live->onMessage($this->merchantId, $this->from, $this->name, $this->text);

            return;
        }
        $account = WhatsAppAccount::withoutGlobalScopes()->find($this->merchantId);
        if ($account !== null) {
            $live->onOfficial($account, $this->from, $this->name, $this->text);
        }
    }
}
