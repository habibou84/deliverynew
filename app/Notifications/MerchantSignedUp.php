<?php

namespace App\Notifications;

use App\Models\Merchant;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvel e-commerçant inscrit en ligne (ou depuis Jibiat Marchand) : cloche, temps réel et notification push du back-office.
 */
class MerchantSignedUp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Merchant $merchant, public ?string $fromApp = null) {}

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
        return [
            'kind' => 'merchant_signup',
            'title' => "Nouvel e-commerçant : {$this->merchant->business_name}".($this->fromApp ? " (depuis {$this->fromApp})" : ''),
            'body' => trim("{$this->merchant->contact_name} · ".PhoneNumber::display($this->merchant->phone).($this->fromApp ? " · compte ouvert depuis {$this->fromApp}, actif." : ' · inscrit en ligne, compte actif.')),
            'merchant_id' => $this->merchant->id,
            'href' => '/admin/marchands?nouveaux=1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return ['title' => $data['title'], 'body' => $data['body'], 'url' => $data['href'], 'tag' => 'merchant-signup-'.$this->merchant->id];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
