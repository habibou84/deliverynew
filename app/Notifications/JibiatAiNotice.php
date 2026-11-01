<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Jibiat IA : fin d'essai proche, volume du mois presque atteint ou dépassé, pause au plafond. Cloche et temps réel
 * des administrateurs de l'entreprise.
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
        $tier = $this->data['tier'] ?? 'Jibiat IA';
        [$title, $body] = match ($this->stage) {
            'trial_ending' => ["Essai Jibiat IA : plus que {$this->data['days']} jour(s)", "Passez à l'abonnement {$tier} pour garder l'IA après l'essai. Votre formule ne change pas."],
            'trial_ended' => ['Essai Jibiat IA terminé', "L'IA est arrêtée. Jibiat continue comme avant ; vous pouvez souscrire à tout moment."],
            'volume_80' => ['Jibiat IA : 80 % des courses du mois', "{$this->data['orders']} courses sur {$this->data['included']} prévues dans {$tier}. Au-delà : {$this->data['overage']} F par course."],
            'volume_100' => ['Jibiat IA : volume du mois atteint', "Les courses en plus sont comptées à {$this->data['overage']} F. Le niveau au-dessus peut revenir moins cher."],
            'paused' => ['Jibiat IA en pause jusqu\'au mois prochain', "La consommation du mois a atteint la limite de {$tier}. Jibiat continue avec les règles habituelles ; une recharge relance l'IA."],
            default => ['Jibiat IA', ''],
        };

        return ['kind' => 'jibiat_ai', 'title' => $title, 'body' => $body, 'href' => '/admin/jibiat-ia'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
