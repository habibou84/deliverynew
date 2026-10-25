<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation dans l'équipe de la console : lien personnel pour choisir son mot de passe (valable 7 jours).
 */
class ConsoleInvitation extends Notification
{
    public function __construct(
        private readonly string $name,
        private readonly string $by,
        private readonly string $role,
        private readonly string $url,
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
            ->subject('Votre accès à la console Jibiat')
            ->greeting("Bonjour {$this->name},")
            ->line("{$this->by} vous invite dans l'équipe de la console Jibiat, avec le rôle « {$this->role} ».")
            ->line('Choisissez votre mot de passe (12 caractères au moins) avec ce lien personnel, valable 7 jours :')
            ->action('Choisir mon mot de passe', $this->url)
            ->line('À chaque connexion, un code vous sera envoyé à cette adresse e-mail.');
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat');
        }

        return $message;
    }
}
