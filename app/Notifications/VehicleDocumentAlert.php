<?php

namespace App\Notifications;

use App\Models\VehicleDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Papier d'un véhicule ou permis d'un livreur qui arrive à sa fin (15 jours avant) ou qui finit aujourd'hui.
 */
class VehicleDocumentAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $kind  soon | expired */
    public function __construct(public VehicleDocument $document, public string $kind) {}

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
        $document = $this->document->loadMissing(['vehicle.courier.user', 'courier.user']);
        $what = $document->vehicle !== null
            ? $document->label().' de la '.mb_strtolower($document->vehicle->label())
            : $document->label().' de '.($document->courier?->user?->name ?? 'un livreur');
        $who = $document->vehicle?->courier?->user?->name;
        $date = $document->expires_at->format('d/m/Y');

        return [
            'kind' => 'vehicle_document',
            'title' => $this->kind === 'expired' ? "🚨 {$what} : fin aujourd'hui" : "📄 {$what} : fin le {$date}",
            'body' => ($this->kind === 'expired'
                ? 'Le papier n\'est plus valable à partir de demain. Ajoutez le nouveau dans la fiche du véhicule.'
                : 'Pensez à le renouveler avant le '.$date.'.').($who ? " Véhicule confié à {$who}." : ''),
            'severity' => $this->kind === 'expired' ? 'alert' : 'note',
            'vehicle_id' => $document->vehicle_id,
            'courier_id' => $document->courier_id,
            'href' => $document->vehicle_id ? "/admin/vehicules?id={$document->vehicle_id}" : '/admin/vehicules?livreurs=1',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
