<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Notifications\Notification;

/**
 * Canal de notification « push » : envoie toWebPush() à chaque appareil abonné de l'utilisateur.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPush $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! WebPush::enabled() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $payload = $notification->toWebPush($notifiable);

        PushSubscription::where('user_id', $notifiable->getKey())->get()
            ->each(fn (PushSubscription $subscription) => $this->push->send($subscription, $payload));
    }
}
