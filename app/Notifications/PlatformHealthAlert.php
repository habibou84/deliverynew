<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Cloche de la console : un service de la plateforme est en panne ou à surveiller (voir Console › Santé).
 */
class PlatformHealthAlert extends Notification
{
    /**
     * @param  list<array{key: string, label: string, status: string, detail: string}>  $problems
     */
    public function __construct(public array $problems) {}

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
            'kind' => 'platform_health',
            'title' => '⚠️ Plateforme : '.collect($this->problems)->pluck('label')->implode(', '),
            'body' => collect($this->problems)->map(fn (array $p) => $p['label'].' : '.$p['detail'])->implode(' · '),
            'href' => '/console/sante',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
