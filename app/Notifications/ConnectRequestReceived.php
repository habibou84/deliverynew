<?php

namespace App\Notifications;

use App\Models\ConnectRequest;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Un e-commerçant demande à devenir client (depuis l'annuaire ou Jibiat Marchand) : à valider par l'équipe.
 * Notification push aussi, pour que la demande ne passe pas inaperçue ; un colis qui attend est mis en avant.
 */
class ConnectRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ConnectRequest $request) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable instanceof User && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $from = $this->request->client_id ? config("connect.clients.{$this->request->client_id}.name") : null;
        $waiting = $this->request->waiting_orders;

        return [
            'kind' => 'connect_request',
            'title' => "Nouveau client potentiel : {$this->request->business_name}".($from ? " (depuis {$from})" : ''),
            'body' => trim("{$this->request->contact_name} · ".PhoneNumber::display($this->request->phone).' · demande à ouvrir un compte.'
                .($waiting > 0 ? " {$waiting} colis attend".($waiting > 1 ? 'ent' : '').' déjà : répondez avant '.$this->request->decide_before?->timezone(config('app.timezone'))->format('d/m à H\hi').'.' : '')),
            'href' => '/admin/marchands?demandes=1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return ['title' => $data['title'], 'body' => $data['body'], 'url' => $data['href'], 'tag' => 'connect-request-'.$this->request->id, 'urgent' => $this->request->waiting_orders > 0];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
