<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail après chaque connexion à la console : date, appareil, adresse IP, et le lien « Ce n'était pas moi ».
 */
class ConsoleLoginAlert extends Notification
{
    public function __construct(
        private readonly string $name,
        private readonly string $when,
        private readonly string $device,
        private readonly string $ip,
        private readonly string $notMeUrl,
        private readonly ?string $mailer = null,
        private readonly ?string $from = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Nouvelle connexion à la console Jibiat')
            ->greeting("Bonjour {$this->name},")
            ->line('Quelqu\'un vient de se connecter à la console Jibiat avec votre compte.')
            ->line("📅 {$this->when}")
            ->line("💻 {$this->device}")
            ->line("📍 Adresse IP {$this->ip}")
            ->line('C\'est vous ? Rien à faire.')
            ->action('Ce n\'était pas moi : tout déconnecter', $this->notMeUrl)
            ->line('Le lien ferme toutes les sessions, oublie les appareils retenus et vous fait choisir un nouveau mot de passe. Il est valable 7 jours.');
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat');
        }

        return $message;
    }
}
