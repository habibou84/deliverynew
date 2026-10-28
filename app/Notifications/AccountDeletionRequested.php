<?php

namespace App\Notifications;

use App\Models\AccountDeletionRequest;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Cloche des administrateurs de l'entreprise : un e-commerçant ou un livreur a demandé la suppression de son compte
 * (annulable jusqu'à l'effacement).
 */
class AccountDeletionRequested extends Notification
{
    public function __construct(public AccountDeletionRequest $request) {}

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
            'kind' => 'account_deletion',
            'title' => '🗑️ Demande de suppression de compte',
            'body' => "{$this->request->name} a demandé la suppression de son compte. Ses données seront effacées le "
                .$this->request->erase_after->timezone(config('app.timezone'))->format('d/m/Y').', sauf si vous annulez avant.',
            'href' => '/admin/utilisateurs',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
