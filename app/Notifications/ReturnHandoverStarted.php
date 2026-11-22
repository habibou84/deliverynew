<?php

namespace App\Notifications;

use App\Models\ReturnHandover;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Le livreur est chez l'e-commerçant avec des colis à lui rendre : cloche, temps réel et téléphone même en veille,
 * pour qu'il confirme dans son application (seul moyen quand le WhatsApp de l'agence n'est pas relié).
 */
class ReturnHandoverStarted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReturnHandover $handover, public string $courierName, public int $count)
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
            'kind' => 'return_handover',
            'title' => "📦 {$this->courierName} est chez vous avec {$this->count} colis",
            'body' => 'Colis retournés à vous rendre : confirmez quand vous les avez en main.',
            'return_handover_id' => $this->handover->id,
            'href' => '/marchand',
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

        return ['title' => $data['title'], 'body' => $data['body'], 'url' => $data['href'], 'tag' => 'return-handover-'.$this->handover->id];
    }
}
