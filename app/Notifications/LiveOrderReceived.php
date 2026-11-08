<?php

namespace App\Notifications;

use App\Models\Live;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Commande prise pendant le live sans l'e-commerçant (WhatsApp automatique, lien du live) : cloche, temps réel et
 * téléphone même en veille. Une seule notification affichée par live sur le téléphone (la dernière remplace la
 * précédente) pour ne pas noyer la vendeuse pendant un gros live.
 */
class LiveOrderReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public Live $live, public string $customer, public string $items, public int $count = 1)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable instanceof User && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'live_order',
            'title' => "🔴 Commande du live : {$this->customer}",
            'body' => trim("{$this->items} · à encaisser : ".Money::format((int) $this->order->cod_amount).' · '.$this->order->tracking_code),
            'order_id' => $this->order->id,
            'live_id' => $this->live->id,
            'href' => "/marchand/live/{$this->live->id}",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /** @return array<string, mixed> */
    public function toWebPush(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $this->count > 1 ? "🔴 {$this->count} commandes pendant votre live" : $data['title'],
            'body' => $data['body'],
            'url' => $data['href'],
            // Une seule notification par live sur le téléphone : la dernière remplace la précédente
            'tag' => 'live-'.$this->live->id,
        ];
    }
}
