<?php

namespace App\Services\Couriers;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Jobs\RingCourier;
use App\Models\Courier;
use App\Models\CourierDevice;
use App\Models\OrderAssignment;
use App\Services\Push\Fcm;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Sonnerie des missions : le téléphone du livreur (application Android) sonne comme un appel pour un nouveau
 * ramassage ou une nouvelle livraison. Les missions confiées ensemble sonnent une seule fois ; sans réponse,
 * deuxième sonnerie deux minutes après, puis l'alerte « injoignable » de l'agence prend le relais.
 */
class MissionRing
{
    /** Missions confiées ensemble (une mission ramassage de plusieurs colis) : une seule sonnerie */
    public const GROUP_SECONDS = 5;

    public const AGAIN_SECONDS = 120;

    private const TYPES = [AssignmentType::Pickup, AssignmentType::Delivery];

    public function __construct(private readonly Fcm $fcm) {}

    /** Mission confiée : sonnerie dans quelques secondes, le temps que les autres missions du même lot arrivent */
    public function queue(OrderAssignment $assignment): void
    {
        if (! in_array($assignment->type, self::TYPES, true) || ! Fcm::configured()) {
            return;
        }
        $userId = Courier::withoutGlobalScopes()->whereKey($assignment->courier_id)->value('user_id');
        if (! CourierDevice::query()->where('user_id', $userId)->exists()) {
            return;
        }
        RingCourier::dispatch($assignment->courier_id, 1)->delay(now()->addSeconds(self::GROUP_SECONDS));
    }

    public function ring(int $courierId, int $attempt): void
    {
        $courier = Courier::withoutGlobalScopes()->with('user')->find($courierId);
        if ($courier?->user === null) {
            return;
        }

        $assignments = OrderAssignment::query()
            ->where('courier_id', $courierId)
            ->whereIn('type', array_map(fn ($t) => $t->value, self::TYPES))
            ->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::Accepted->value])
            ->whereNull('ring_answered_at')
            ->when($attempt === 1,
                fn ($q) => $q->whereNull('rang_at')->where('assigned_at', '>=', now()->subMinutes(15)),
                fn ($q) => $q->where('ring_count', 1)->where('rang_at', '<=', now()->subSeconds(self::AGAIN_SECONDS - 20)))
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->with(['merchant' => fn ($m) => $m->withoutGlobalScopes(), 'pickupZone', 'deliveryZone'])])
            ->orderBy('id')
            ->get();
        if ($assignments->isEmpty()) {
            return;
        }

        $data = ['type' => 'ring', 'attempt' => (string) $attempt, ...$this->describe($assignments, $attempt)];
        $sent = $this->toDevices($courier->user_id, $data);

        OrderAssignment::query()->whereKey($assignments->pluck('id'))->update(['rang_at' => now(), 'ring_count' => $attempt]);
        if ($sent && $attempt === 1) {
            RingCourier::dispatch($courierId, 2)->delay(now()->addSeconds(self::AGAIN_SECONDS));
        }
    }

    /**
     * Le livreur a répondu (accepter, refuser, « j'ai vu ») : plus de rappel, et la sonnerie s'arrête sur ses
     * autres téléphones.
     *
     * @param  list<int>  $ids
     */
    public function answer(Courier $courier, array $ids): int
    {
        $count = OrderAssignment::query()->where('courier_id', $courier->id)->whereKey($ids)->whereNull('ring_answered_at')
            ->update(['ring_answered_at' => now()]);
        if ($count > 0 && Fcm::configured()) {
            $this->toDevices($courier->user_id, ['type' => 'ring_stop', 'ids' => implode(',', $ids)]);
        }

        return $count;
    }

    /**
     * @param  Collection<int, OrderAssignment>  $assignments
     * @return array<string, string>
     */
    public function describe(Collection $assignments, int $attempt = 1): array
    {
        $first = $assignments->first();
        $order = $first->order;
        $pickups = $assignments->where('type', AssignmentType::Pickup);
        $urgent = $assignments->contains(fn (OrderAssignment $a) => (bool) $a->order?->is_express);

        if ($assignments->count() > 1 && $pickups->count() !== $assignments->count()) {
            $title = "{$assignments->count()} nouvelles missions";
            $line1 = $pickups->count().' ramassage'.($pickups->count() > 1 ? 's' : '').', '.($assignments->count() - $pickups->count()).' livraison'.($assignments->count() - $pickups->count() > 1 ? 's' : '');
            $line2 = '';
        } elseif ($first->type === AssignmentType::Pickup) {
            $title = 'Nouveau ramassage';
            $line1 = (string) $order?->merchant?->business_name;
            $shops = $pickups->pluck('order.merchant_id')->unique()->count();
            $line2 = trim(($order?->pickupZone?->name ?? '').' · '.$assignments->count().' colis'.($shops > 1 ? " · {$shops} boutiques" : ''), ' ·');
        } else {
            $title = $assignments->count() > 1 ? "{$assignments->count()} nouvelles livraisons" : 'Nouvelle livraison';
            $line1 = trim(($order?->recipient_name ?: 'Client').' · '.($order?->deliveryZone?->name ?? ''), ' ·');
            $line2 = $assignments->count() > 1 ? '' : ((int) $order?->cod_amount > 0 ? 'À encaisser : '.Money::format((int) $order->cod_amount) : 'Rien à encaisser');
        }
        if ($urgent) {
            $title = '⚡ Urgent : '.mb_strtolower(mb_substr($title, 0, 1)).mb_substr($title, 1);
        }
        $ids = $assignments->pluck('id')->implode(',');

        return [
            'ids' => $ids,
            'title' => $title,
            'line1' => $line1,
            'line2' => $line2,
            'path' => "/livreur/appel?ids={$ids}&attempt={$attempt}",
        ];
    }

    /** @param  array<string, string>  $data */
    private function toDevices(int $userId, array $data): bool
    {
        $sent = false;
        foreach (CourierDevice::query()->where('user_id', $userId)->get() as $device) {
            $result = $this->fcm->send($device->token, $data);
            if ($result === false) {
                $device->delete();
            }
            $sent = $sent || $result === true;
        }

        return $sent;
    }
}
