<?php

namespace App\Services\Legacy;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\LedgerEntryType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\LegacyPurge;
use App\Models\LegacyPurgeItem;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nettoyage des anciennes courses reprises de l'ancienne application : courses jamais terminées là-bas dont le jour de
 * livraison est passé depuis plus de N jours. Sans message, webhook, gain livreur ni facture ; l'argent ne bouge pas.
 *
 * - jamais ramassées → annulées ;
 * - ramassées, au dépôt, en échec ou reportées → retournées à l'e-commerçant ; celles qu'un livreur a encore en
 *   garde : retour décidé, colis à rendre (point de caisse du livreur) ;
 * - en chemin → aucune action : liste à vérifier, livreur par livreur (livrées ou retournées) ;
 * - livrées dont l'argent n'est pas soldé → pas touchées (caisse).
 *
 * Une course nettoyée n'est plus recopiée depuis l'ancienne application. Un nettoyage s'annule pendant 7 jours.
 */
class LegacyOrdersPurge
{
    public const NEVER_PICKED = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress];

    public const NOT_DELIVERED = [OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned, OrderStatus::DeliveryFailed,
        OrderStatus::Rescheduled, OrderStatus::ReturnAssigned, OrderStatus::Returning];

    public const ON_THE_WAY = [OrderStatus::OutForDelivery];

    /** Courses traitées par étape de nettoyage */
    public const BATCH = 300;

    public const NOTE = 'Clôturée lors du nettoyage de la reprise de l\'ancienne application';

    /**
     * Anciennes courses reprises, pas encore nettoyées, prévues il y a plus de $days jours.
     *
     * @param  array{merchant_id?: ?int, courier_id?: ?int}  $filters
     */
    public function scope(Company $company, int $days, array $filters = []): Builder
    {
        $before = now($company->timezone ?: config('app.timezone'))->subDays($days)->toDateString();

        return Order::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereNotNull('legacy_id')->whereNull('legacy_purged_at')->whereNull('deleted_at')
            ->whereRaw(Order::DELIVERY_DAY_SQL.' < ?', [$before])
            ->when($filters['merchant_id'] ?? null, fn ($q, $id) => $q->where('merchant_id', $id))
            ->when($filters['courier_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('held_by_courier_id', $id)
                ->orWhere('delivery_courier_id', $id)->orWhere('pickup_courier_id', $id)));
    }

    /**
     * Simulation : nombre de courses par cas, rien n'est changé.
     *
     * @return array{never_picked: int, not_delivered: int, held: int, on_the_way: int, unsettled: int, total: int}
     */
    public function simulate(Company $company, int $days, array $filters = []): array
    {
        $count = fn (array $statuses) => (clone $this->scope($company, $days, $filters))->whereIn('status', OrderStatus::values($statuses));
        $never = $count(self::NEVER_PICKED)->count();
        $notDelivered = $count(self::NOT_DELIVERED)->count();

        return [
            'never_picked' => $never,
            'not_delivered' => $notDelivered,
            'held' => $count(self::NOT_DELIVERED)->whereNotNull('held_by_courier_id')->count(),
            'on_the_way' => $count(self::ON_THE_WAY)->count(),
            // Livrées, argent encore chez le livreur ou pas reversé : laissées à la caisse
            'unsettled' => $count([OrderStatus::Delivered])->where(fn ($q) => $q
                ->whereHas('cashCollection', fn ($c) => $c->inCourierHands())
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('merchant_ledger_entries')
                    ->whereColumn('merchant_ledger_entries.order_id', 'orders.id')->whereNull('merchant_ledger_entries.payout_id')))->count(),
            'total' => $never + $notDelivered,
        ];
    }

    /** Lance un nettoyage (étapes en arrière-plan) */
    public function start(Company $company, User $by, int $days, array $filters, array $include): LegacyPurge
    {
        if (LegacyPurge::query()->where('company_id', $company->id)->where('status', 'running')->exists()) {
            throw new RuntimeException('Un nettoyage est déjà en cours : attendez qu\'il se termine.');
        }
        $counts = $this->simulate($company, $days, $filters);

        return LegacyPurge::create([
            'company_id' => $company->id,
            'user_id' => $by->id,
            'min_days' => $days,
            'filters' => [...$filters, 'include' => array_values($include)],
            'counts' => $counts,
            'total' => (in_array('never_picked', $include, true) ? $counts['never_picked'] : 0)
                + (in_array('not_delivered', $include, true) ? $counts['not_delivered'] : 0),
        ]);
    }

    /**
     * Une étape : jusqu'à BATCH courses clôturées. Renvoie true quand le nettoyage est fini.
     */
    public function step(LegacyPurge $purge): bool
    {
        $company = Company::findOrFail($purge->company_id);
        $include = $purge->filters['include'] ?? ['never_picked', 'not_delivered'];
        $statuses = [
            ...(in_array('never_picked', $include, true) ? self::NEVER_PICKED : []),
            ...(in_array('not_delivered', $include, true) ? self::NOT_DELIVERED : []),
        ];
        $orders = $statuses === [] ? collect() : $this->scope($company, $purge->min_days, $purge->filters ?? [])
            ->whereIn('status', OrderStatus::values($statuses))->orderBy('id')->limit(self::BATCH)->get();

        foreach ($orders as $order) {
            DB::transaction(fn () => $this->close($purge, $order, in_array($order->status, self::NEVER_PICKED, true) ? 'cancel' : 'return'));
        }
        $purge->increment('done', $orders->count());

        if ($orders->count() < self::BATCH) {
            $purge->forceFill(['status' => 'done', 'finished_at' => now(), 'done' => $purge->items()->count()])->save();

            return true;
        }

        return false;
    }

    /** Annule un nettoyage (7 jours) : les courses reviennent à leur état d'avant, sauf celles touchées depuis */
    public function undo(LegacyPurge $purge): int
    {
        if (! $purge->canBeUndone()) {
            throw new RuntimeException('Ce nettoyage ne peut plus être annulé.');
        }

        $restored = 0;
        $purge->items()->with('order')->chunkById(200, function ($items) use ($purge, &$restored) {
            foreach ($items as $item) {
                $order = $item->order;
                // Course changée depuis le nettoyage (traitée à la main) : on n'y touche pas
                if ($order === null || $order->legacy_purge_id !== $purge->id) {
                    continue;
                }
                DB::transaction(function () use ($item, $order, &$restored) {
                    $previous = $item->previous;
                    OrderAssignment::query()->whereIn('id', array_keys($previous['assignments'] ?? []))->get()
                        ->each(fn (OrderAssignment $a) => $a->forceFill(['status' => $previous['assignments'][$a->id], 'completed_at' => null])->save());
                    OrderEvent::query()->where('order_id', $order->id)->where('meta->legacy_purge', $item->legacy_purge_id)->delete();
                    $order->forceFill([...$previous['order'], 'legacy_purged_at' => null, 'legacy_purge_id' => null])->save();
                    Order::withoutGlobalScopes()->whereKey($order->id)->update(['status_changed_at' => $previous['status_changed_at'] ?? now()]);
                    $restored++;
                });
            }
        });
        $purge->forceFill(['status' => 'undone', 'undone_at' => now()])->save();

        return $restored;
    }

    /**
     * Courses en chemin à vérifier, par livreur.
     *
     * @return list<array{courier_id: ?int, name: string, count: int, oldest: ?string}>
     */
    public function onTheWayByCourier(Company $company, int $days): array
    {
        $courierId = DB::raw('COALESCE(orders.delivery_courier_id, orders.held_by_courier_id) AS courier_id');

        return $this->scope($company, $days)->whereIn('status', OrderStatus::values(self::ON_THE_WAY))
            ->select($courierId)->selectRaw('COUNT(*) AS n, MIN('.Order::DELIVERY_DAY_SQL.') AS oldest')
            ->groupBy(DB::raw('COALESCE(orders.delivery_courier_id, orders.held_by_courier_id)'))->orderByDesc('n')->get()
            ->map(function ($row) use ($company) {
                $user = $row->courier_id ? DB::table('couriers')->join('users', 'users.id', '=', 'couriers.user_id')
                    ->where('couriers.company_id', $company->id)->where('couriers.id', $row->courier_id)->value('users.name') : null;

                return ['courier_id' => $row->courier_id ? (int) $row->courier_id : null, 'name' => $user ?? 'Sans livreur connu',
                    'count' => (int) $row->n, 'oldest' => $row->oldest ? substr((string) $row->oldest, 0, 10) : null];
            })->all();
    }

    /** Courses en chemin d'un livreur (null : sans livreur connu) */
    public function onTheWay(Company $company, int $days, ?int $courierId): Builder
    {
        return $this->scope($company, $days)->whereIn('status', OrderStatus::values(self::ON_THE_WAY))
            ->when($courierId, fn ($q) => $q->where(fn ($q) => $q->where('delivery_courier_id', $courierId)
                ->orWhere(fn ($q) => $q->whereNull('delivery_courier_id')->where('held_by_courier_id', $courierId))),
                fn ($q) => $q->whereNull('delivery_courier_id')->whereNull('held_by_courier_id'))
            ->with('merchant:id,business_name')->orderByRaw(Order::DELIVERY_DAY_SQL)->orderBy('id');
    }

    /**
     * Vérification d'une course en chemin : livrée (argent au point de caisse du livreur) ou retournée.
     *
     * @param  list<int>  $ids
     */
    public function resolve(Company $company, int $days, array $ids, string $outcome): int
    {
        $orders = $this->scope($company, $days)->whereIn('status', OrderStatus::values(self::ON_THE_WAY))->whereKey($ids)->get();
        foreach ($orders as $order) {
            DB::transaction(fn () => $outcome === 'delivered' ? $this->deliver($order) : $this->close(null, $order, 'return'));
        }

        return $orders->count();
    }

    /** Clôture une course ; avec un nettoyage, l'état d'avant est gardé pour pouvoir l'annuler */
    private function close(?LegacyPurge $purge, Order $order, string $how): void
    {
        $previous = [
            'order' => $order->only(['status', 'return_requested', 'held_by_courier_id', 'held_since', 'cancelled_at', 'returned_at']),
            'status_changed_at' => $order->status_changed_at?->toDateTimeString(),
            'assignments' => [],
        ];
        $held = $order->held_by_courier_id !== null;
        $status = match (true) {
            $how === 'cancel' => OrderStatus::Cancelled,
            // Colis encore chez un livreur : retour décidé, à rendre à l'e-commerçant (point de caisse du livreur)
            $held => OrderStatus::DeliveryFailed,
            default => OrderStatus::Returned,
        };

        // Missions en cours arrêtées, sauf la garde du colis à rendre
        foreach (OrderAssignment::query()->where('order_id', $order->id)->active()->get() as $assignment) {
            $previous['assignments'][$assignment->id] = $assignment->status->value;
            $assignment->forceFill(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()])->save();
        }

        $order->forceFill([
            'status' => $status,
            'return_requested' => $status === OrderStatus::DeliveryFailed ? true : $order->return_requested,
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : $order->cancelled_at,
            'returned_at' => $status === OrderStatus::Returned ? now() : $order->returned_at,
            'held_by_courier_id' => $status === OrderStatus::DeliveryFailed ? $order->held_by_courier_id : null,
            'held_since' => $status === OrderStatus::DeliveryFailed ? $order->held_since : null,
            'legacy_purged_at' => now(),
            'legacy_purge_id' => $purge?->id,
        ])->save();

        $this->note($order, $purge, $status === OrderStatus::DeliveryFailed
            ? self::NOTE.' : retour à l\'e-commerçant, colis à rendre par le livreur.'
            : self::NOTE.($status === OrderStatus::Cancelled ? ' : annulée (jamais ramassée).' : ' : retournée à l\'e-commerçant.'));

        if ($purge) {
            LegacyPurgeItem::create(['legacy_purge_id' => $purge->id, 'order_id' => $order->id, 'previous' => $previous]);
        }
    }

    /** Livrée d'après le livreur : argent à remettre (point de caisse), dû à l'e-commerçant comme à la reprise */
    private function deliver(Order $order): void
    {
        $courierId = $order->delivery_courier_id ?? $order->held_by_courier_id;
        OrderAssignment::query()->where('order_id', $order->id)->active()->get()->each(fn (OrderAssignment $a) => $a->forceFill([
            'status' => $a->type === AssignmentType::Delivery ? AssignmentStatus::Completed : AssignmentStatus::Cancelled,
            'completed_at' => now(),
        ])->save());

        $order->forceFill([
            'status' => OrderStatus::Delivered,
            'delivered_at' => now(),
            'collected_amount' => $order->cod_amount,
            'held_by_courier_id' => null,
            'held_since' => null,
            'legacy_purged_at' => now(),
        ])->save();

        if ($order->cod_amount > 0 && ! CashCollection::query()->where('order_id', $order->id)->exists()) {
            CashCollection::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'amount_expected' => $order->cod_amount,
                'amount_collected' => $order->cod_amount,
                'method' => PaymentMethod::Cash,
                'received_by_company' => false,
                'collected_at' => now(),
            ]);
        }
        if (! MerchantLedgerEntry::query()->where('order_id', $order->id)->exists()) {
            foreach ([[LedgerEntryType::CodCredit, (int) $order->cod_amount, 'Encaissé à la livraison (ancienne application)'],
                [LedgerEntryType::DeliveryFee, -(int) $order->delivery_fee, 'Frais de livraison']] as [$type, $amount, $description]) {
                if ($amount !== 0) {
                    MerchantLedgerEntry::create(['company_id' => $order->company_id, 'merchant_id' => $order->merchant_id,
                        'type' => $type, 'amount' => $amount, 'order_id' => $order->id, 'description' => $description]);
                }
            }
        }
        $this->note($order, null, self::NOTE.' : livrée d\'après le livreur, argent à remettre à la caisse.');
    }

    private function note(Order $order, ?LegacyPurge $purge, string $text): void
    {
        OrderEvent::create([
            'order_id' => $order->id,
            'type' => OrderEventType::Note,
            'actor_type' => 'system',
            'actor_name' => 'Nettoyage de la reprise',
            'visible_to_merchant' => false,
            'note' => $text,
            'meta' => ['legacy_purge' => $purge?->id],
        ]);
    }
}
