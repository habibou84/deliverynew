<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail du code de connexion à la console (super administrateur), valable 10 minutes.
 */
class ConsoleLoginCode extends Notification
{
    public function __construct(private readonly string $code, private readonly string $ip, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Code de connexion à la console Jibiat : {$this->code}")
            ->greeting('Votre code de connexion')
            ->line("**{$this->code}**")
            ->line('Il est valable 10 minutes. Demande faite depuis l\'adresse '.$this->ip.'.')
            ->line('Si ce n\'est pas vous : ne donnez ce code à personne et changez votre mot de passe tout de suite.');
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat');
        }

        return $message;
    }
}
