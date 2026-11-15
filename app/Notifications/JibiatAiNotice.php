<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Jibiat IA : points du mois presque épuisés ou épuisés, pause. Cloche et temps réel des administrateurs de
 * l'entreprise.
 */
class JibiatAiNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $stage, public array $data = []) {}

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
        [$title, $body] = match ($this->stage) {
            'points_80' => ['Jibiat IA : 80 % de vos points du mois', "{$this->data['used']} points utilisés sur {$this->data['points']}. Ils repartent à zéro le 1er du mois."],
            'points_100' => ['Jibiat IA : points du mois épuisés', "L'IA s'arrête jusqu'au 1er du mois ; Jibiat continue avec les règles habituelles (envoyez les commandes par écrit)."],
            'paused' => ['Jibiat IA en pause jusqu\'au mois prochain', "Jibiat continue avec les règles habituelles jusqu'au 1er du mois (envoyez les commandes par écrit)."],
            default => ['Jibiat IA', ''],
        };

        return ['kind' => 'jibiat_ai', 'title' => $title, 'body' => $body, 'href' => '/admin/abonnement#jibiat-ia'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
