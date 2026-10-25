<?php

namespace App\Notifications;

use App\Models\SupportSession;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Cloche des administrateurs de l'entreprise : l'assistance Jibiat a ouvert leur espace (qui, jusqu'à quand, pourquoi).
 */
class SupportSessionOpened extends Notification
{
    public function __construct(public SupportSession $session, public string $openedBy) {}

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
            'kind' => 'support_session',
            'title' => '🛟 L\'assistance Jibiat a ouvert votre espace',
            'body' => "Par {$this->openedBy}, jusqu'à ".$this->session->expires_at->timezone(config('app.timezone'))->format('H:i').' · Motif : '.$this->session->reason,
            'href' => '/admin/reglages/assistance',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
