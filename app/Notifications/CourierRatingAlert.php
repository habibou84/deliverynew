<?php

namespace App\Notifications;

use App\Models\CourierRating;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Avis grave sur un livreur (« m'a demandé plus d'argent », colis abîmé, 1 étoile…) :
 * cloche et temps réel des responsables, à traiter tout de suite.
 */
class CourierRatingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CourierRating $rating) {}

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
        $rating = $this->rating->loadMissing(['courier.user', 'order']);
        $tags = collect($rating->tagList())->reject(fn ($t) => $t['good'])->pluck('label');
        $what = $tags->isNotEmpty() ? '« '.$tags->join(' », « ').' »' : "{$rating->score} étoile".($rating->score > 1 ? 's' : '');

        return [
            'kind' => 'courier_rating',
            'title' => '⚠️ '.($rating->courier?->user?->name ?? 'Livreur').' : '.$what,
            'body' => 'Avis du client sur la course '.$rating->order?->tracking_code
                .($rating->comment ? ' : « '.$rating->comment.' »' : '').'. À vérifier.',
            'severity' => 'alert',
            'courier_id' => $rating->courier_id,
            'order_id' => $rating->order_id,
            'href' => "/admin/livreurs/{$rating->courier_id}/notes",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
