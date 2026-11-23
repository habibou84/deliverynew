<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\ParcelGap;
use App\Models\User;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Colis chez les livreurs : ce que chacun a encore en main (ramassé, en livraison,
 * échec ou report non rendu) et la réception de ces colis au dépôt, notamment
 * lors du point de caisse.
 */
class ParcelCustody
{
    // Le livreur est en route avec ces colis : ils ne peuvent pas être rendus au dépôt
    public const ON_THE_ROAD = [OrderStatus::OutForDelivery, OrderStatus::Returning];

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderJournal $journal,
    ) {}

    public static function heldQuery(): Builder
    {
        return Order::query()->whereNotNull('held_by_courier_id');
    }

    /**
     * Colis en main d'un livreur, du plus ancien au plus récent.
     *
     * @return Collection<int, Order>
     */
    public function held(Courier $courier): Collection
    {
        return self::heldQuery()->where('held_by_courier_id', $courier->id)
            ->with(['merchant:id,business_name', 'deliveryZone:id,name', 'lastIncidentReason:id,label'])
            ->orderBy('held_since')->orderBy('id')
            ->get();
    }

    /**
     * Courses que le livreur n'a pas encore clôturées (ni livrées ni déclarées en échec).
     *
     * @return Collection<int, Order>
     */
    public function onTheRoad(Courier $courier): Collection
    {
        return self::heldQuery()->where('held_by_courier_id', $courier->id)
            ->where('status', OrderStatus::OutForDelivery->value)
            ->orderBy('held_since')->orderBy('id')
            ->get(['id', 'tracking_code', 'recipient_name', 'status', 'held_since']);
    }

    /**
     * Colis que le livreur doit rendre au dépôt : en main, ni en route, ni gardé pour sa livraison à venir,
     * ni à rapporter au marchand (mission retour qui lui est confiée), ni déjà déposé dans le bac (à contrôler).
     */
    public static function isDue(Order $order): bool
    {
        return $order->held_by_courier_id !== null
            && $order->parcel_drop_id === null
            && ! in_array($order->status, self::ON_THE_ROAD, true)
            && PickupCustody::keptFor($order) === null
            && ! self::returnMission($order);
    }

    /** Colis à rapporter au marchand par le livreur qui l'a : il ne passe pas par le dépôt */
    public static function returnMission(Order $order): bool
    {
        return $order->status === OrderStatus::ReturnAssigned && $order->return_courier_id !== null
            && $order->return_courier_id === $order->held_by_courier_id;
    }

    /**
     * Colis que le livreur doit rendre au dépôt.
     *
     * @return Collection<int, Order>
     */
    public function due(Courier $courier): Collection
    {
        return $this->held($courier)->filter(fn (Order $o) => self::isDue($o))->values();
    }

    /**
     * Réception au dépôt des colis rendus par le livreur. Un colis ramassé passe « Au dépôt » ; les autres gardent
     * leur statut (échec, report…) pour que le dispatch décide de la suite, mais ne sont plus chez le livreur.
     *
     * $missing (point de caisse, réception au dépôt) : chaque colis que le livreur devait rendre et qui n'est pas
     * réceptionné doit avoir un motif (order_id => [reason, note]). Un écart est créé pour chacun ; le livreur est
     * prévenu (colis rendus, colis non réceptionnés et motif) et confirme ou conteste. Sans $missing : réception
     * simple, sans contrôle des colis restants.
     *
     * @param  list<int>  $orderIds
     * @param  array<int, array{reason: string, note?: ?string}>|null  $missing
     * @return Collection<int, Order> colis reçus
     */
    public function receive(User $actor, Courier $courier, array $orderIds, ?array $missing = null): Collection
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === [] && $missing === null) {
            return collect();
        }

        [$received, $gaps] = DB::transaction(function () use ($actor, $courier, $orderIds, $missing) {
            $orders = Order::query()->whereIn('id', $orderIds)
                ->where('held_by_courier_id', $courier->id)
                ->orderBy('id') // ordre stable de la liste des colis rendus (PostgreSQL ne garantit aucun ordre)
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw new BusinessRuleException('Certains colis ne sont pas (ou plus) chez ce livreur.', 'returned_order_ids');
            }

            $onTheRoad = $orders->filter(fn (Order $o) => in_array($o->status, self::ON_THE_ROAD, true));

            if ($onTheRoad->isNotEmpty()) {
                throw new BusinessRuleException(
                    'Course(s) encore en route : '.$onTheRoad->pluck('tracking_code')->implode(', ').'. Le livreur doit d\'abord indiquer si elles sont livrées ou en échec.',
                    'returned_order_ids',
                );
            }

            $gaps = $missing === null ? collect() : $this->recordGaps($actor, $courier, $orderIds, $missing);

            $note = 'Colis rendu au dépôt par '.($courier->user?->name ?? 'le livreur');

            return [$orders->map(fn (Order $order) => $this->toHub($actor, $order, $note)), $gaps];
        });

        if ($missing !== null && ($received->isNotEmpty() || $gaps->isNotEmpty())) {
            app(ParcelGaps::class)->notifyReceipt($actor, $courier, $received, $gaps);
        }

        return $received;
    }

    /**
     * Colis du livreur à rendre, non réceptionnés : un motif chacun, un écart chacun.
     *
     * @param  list<int>  $receivedIds
     * @param  array<int, array{reason: string, note?: ?string}>  $missing
     * @return Collection<int, ParcelGap>
     */
    private function recordGaps(User $actor, Courier $courier, array $receivedIds, array $missing): Collection
    {
        $left = $this->due($courier)->reject(fn (Order $o) => in_array($o->id, $receivedIds, true));
        $without = $left->filter(fn (Order $o) => ! isset($missing[$o->id]['reason']) || ! isset(ParcelGap::REASONS[$missing[$o->id]['reason']])
            || ($missing[$o->id]['reason'] === 'other' && blank($missing[$o->id]['note'] ?? null)));

        if ($without->isNotEmpty()) {
            throw new BusinessRuleException(
                'Indiquez pourquoi ces colis ne sont pas réceptionnés : '.$without->pluck('tracking_code')->implode(', ').'.',
                'missing',
            );
        }

        return $left->map(fn (Order $o) => ParcelGap::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'order_id' => $o->id,
            'source' => ParcelGap::CASH_POINT,
            'reported_by' => $actor->id,
            'reason' => $missing[$o->id]['reason'],
            'note' => filled($missing[$o->id]['note'] ?? null) ? mb_substr(trim($missing[$o->id]['note']), 0, 500) : null,
        ])->setRelation('order', $o))->values();
    }

    /**
     * Colis au dépôt : plus chez aucun livreur, garde prévue terminée ; un colis ramassé passe « Au dépôt ».
     */
    public function toHub(User $actor, Order $order, string $note): Order
    {
        // Colis au dépôt : un écart encore ouvert ou contesté sur ce colis est clos
        ParcelGap::query()->withoutGlobalScopes()->where('order_id', $order->id)->whereNull('resolution')
            ->update(['resolution' => ParcelGap::AT_HUB, 'resolved_by' => $actor->id, 'resolved_at' => now()]);

        $order->custody_plan = null;
        $order->parcel_drop_id = null;

        if ($order->status === OrderStatus::PickedUp) {
            $order->save();

            return $this->workflow->transition($actor, $order, OrderStatus::AtHub, ['note' => $note]);
        }

        $order->handTo(null);
        $order->save();

        $this->journal->record($order, $actor, OrderEventType::ParcelReceived, [
            'note' => $note,
            'visible_to_merchant' => false,
        ]);

        return $order;
    }

    /**
     * Données d'un colis pour les écrans de caisse et de suivi.
     *
     * @return array<string, mixed>
     */
    public static function parcelData(Order $order): array
    {
        return [
            'id' => $order->id,
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'merchant' => $order->merchant?->business_name,
            'recipient_name' => $order->recipient_name,
            'recipient_phone' => $order->recipient_phone,
            'zone' => $order->deliveryZone?->name,
            'cod_amount' => $order->cod_amount,
            'incident' => $order->lastIncidentReason?->label,
            'rescheduled_to' => $order->status === OrderStatus::Rescheduled ? $order->delivery_scheduled_date?->toDateString() : null,
            'return_requested' => $order->return_requested,
            'held_since' => $order->held_since,
            'on_the_road' => in_array($order->status, self::ON_THE_ROAD, true),
            // Mission ramassage : gardé jusqu'à sa livraison (pas « à rendre »), ou à rapporter au dépôt
            'custody_plan' => $order->custody_plan,
            'kept_for' => PickupCustody::keptFor($order),
            // À rendre au dépôt (sinon : en route, gardé pour sa livraison, ou à rapporter au marchand)
            'due' => self::isDue($order),
            'return_mission' => self::returnMission($order),
            // Déposé dans le bac du dépôt, en attente du contrôle
            'dropped' => $order->parcel_drop_id !== null,
            // Gardé, mais sa date de livraison est passée : l'agence dira quand le livrer ou le rapporter
            'date_to_confirm' => ($kept = PickupCustody::keptFor($order)) !== null && $kept < now(Company::find($order->company_id)?->timezone ?: config('app.timezone'))->toDateString(),
        ];
    }
}
