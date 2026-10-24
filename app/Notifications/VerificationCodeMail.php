<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de secours (gratuit) quand ni WhatsApp ni SMS ne sont possibles : code de vérification à recopier sur la
 * page, avec un lien qui le recopie tout seul.
 */
class VerificationCodeMail extends Notification
{
    public function __construct(
        public readonly string $code,
        private readonly string $what,
        private readonly string $company,
        private readonly ?string $link,
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
            ->subject("{$this->code} est votre code {$this->company}")
            ->greeting('Bonjour,')
            ->line("Votre code {$this->what} : **{$this->code}**")
            ->line('Il expire dans 30 minutes. Ne le communiquez à personne.');
        if ($this->link !== null) {
            $message->action('Continuer sur la page', $this->link);
        }
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, $this->company);
        }

        return $message;
    }
}
