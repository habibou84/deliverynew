<?php

namespace App\Notifications;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * L'e-commerçant a changé son lieu de ramassage en validant sa position sur place : ses prix partent désormais de
 * cette zone.
 */
class MerchantPickupZoneChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Merchant $merchant, public ?string $from, public string $to) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'merchant_pickup_zone',
            'title' => '📍 '.$this->merchant->business_name.' : lieu de ramassage changé',
            'body' => ($this->from ? "{$this->from} → " : '').$this->to.'. Validé sur place avec la position du téléphone ; ses prix partent maintenant de là.',
            'severity' => 'note',
            'merchant_id' => $this->merchant->id,
            'href' => '/admin/marchands?id='.$this->merchant->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
