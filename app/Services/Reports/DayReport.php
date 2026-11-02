<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Point du jour (ou d'une période) de la page Courses et du tableau de bord : courses prévues et livrées, recette de
 * livraison, argent encaissé chez les clients (déjà à la caisse ou encore chez les livreurs), montant à reverser aux
 * e-commerçants, nouvelles courses et chiffres par livreur.
 *
 * Les courses prévues sont celles dont le jour de livraison tombe dans la période ; les livrées sont celles livrées
 * pendant la période (quel que soit leur jour prévu). Les montants portent sur les livrées.
 */
class DayReport
{
    /** Livreurs listés au plus dans « Livreurs du jour » */
    public const COURIERS = 8;

    /** @return array<string, mixed> */
    public function build(Company $company, string $from, bool $withMoney, ?string $to = null): array
    {
        $to ??= $from;
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay();
        $orders = fn () => Order::withoutGlobalScopes()->where('company_id', $company->id);

        $planned = $orders()->forPeriod($from, $to, 'delivery')->get(['id', 'status']);
        $count = fn (array $statuses) => $planned->whereIn('status', $statuses)->count();
        $delivered = $orders()->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start->utc(), $end->utc()])
            ->get(['id', 'merchant_id', 'is_shipping', 'delivery_courier_id']);
        // Nouvelles courses : créées pendant la période, dont celles à livrer après
        $created = $orders()->whereBetween('created_at', [$start->utc(), $end->utc()]);

        $report = [
            'day' => $start->toDateString(),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'counts' => [
                'planned' => $planned->count(),
                'delivered' => $delivered->count(),
                'delivered_planned' => $planned->where('status', OrderStatus::Delivered)->count(),
                'in_progress' => $count([
                    OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
                    OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned, OrderStatus::OutForDelivery,
                ]),
                'failed' => $count([OrderStatus::DeliveryFailed]),
                'rescheduled' => $count([OrderStatus::Rescheduled]),
                'returned' => $count([OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Returned]),
                'cancelled' => $count([OrderStatus::Cancelled, OrderStatus::Rejected]),
                'created' => (clone $created)->count(),
                'created_later' => (clone $created)->whereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$end->toDateString()])->count(),
            ],
            'money' => null,
            'couriers' => $this->couriers($company, $delivered, $withMoney),
        ];
        if (! $withMoney) {
            return $report;
        }

        $ids = $delivered->pluck('id');
        $ledger = MerchantLedgerEntry::withoutGlobalScopes()->whereIn('order_id', $ids)
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::ShippingFee->value, LedgerEntryType::OtherFee->value])
            ->groupBy('type')->selectRaw('type, SUM(amount) AS total')->pluck('total', 'type');
        $fee = fn (LedgerEntryType $type) => -(int) ($ledger[$type->value] ?? 0);
        $fees = $fee(LedgerEntryType::DeliveryFee) + $fee(LedgerEntryType::ReturnFee);

        $collections = CashCollection::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('order_id', $ids);
        $collected = (int) (clone $collections)->sum('amount_collected');
        $inHands = (clone $collections)->inCourierHands();
        $withCouriers = (int) (clone $inHands)->sum('amount_collected');

        $report['money'] = [
            'fees' => $fees,
            'shipping_fees' => $fee(LedgerEntryType::ShippingFee),
            'other_fees' => $fee(LedgerEntryType::OtherFee),
            'collected' => $collected,
            'at_desk' => $collected - $withCouriers,
            'with_couriers' => $withCouriers,
            'couriers_holding' => (clone $inHands)->where('amount_collected', '>', 0)->distinct()->count('courier_id'),
            'net_to_merchants' => $collected - $fees - $fee(LedgerEntryType::ShippingFee) - $fee(LedgerEntryType::OtherFee),
            'merchants' => $delivered->pluck('merchant_id')->unique()->count(),
        ];

        return $report;
    }

    /**
     * Livreurs de la période : courses livrées, colis en main maintenant et argent encaissé (si droit caisse),
     * du plus actif au moins actif.
     *
     * @param  Collection<int, Order>  $delivered
     * @return list<array{id: int, name: ?string, delivered: int, in_hand: int, collected: ?int}>
     */
    private function couriers(Company $company, $delivered, bool $withMoney): array
    {
        $byCourier = $delivered->whereNotNull('delivery_courier_id')->groupBy('delivery_courier_id');
        $inHand = Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('held_by_courier_id')
            ->whereNotIn('status', OrderStatus::values(array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal())))
            ->groupBy('held_by_courier_id')->selectRaw('held_by_courier_id, COUNT(*) AS n')->pluck('n', 'held_by_courier_id');
        $ids = $byCourier->keys()->merge($inHand->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $collected = $withMoney
            ? CashCollection::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('order_id', $delivered->pluck('id'))
                ->groupBy('courier_id')->selectRaw('courier_id, SUM(amount_collected) AS total')->pluck('total', 'courier_id')
            : collect();
        $names = Courier::withoutGlobalScopes()->whereIn('id', $ids)->with('user')->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]);

        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'name' => $names[$id] ?? null,
            'delivered' => isset($byCourier[$id]) ? $byCourier[$id]->count() : 0,
            'in_hand' => (int) ($inHand[$id] ?? 0),
            'collected' => $withMoney ? (int) ($collected[$id] ?? 0) : null,
        ])->sortByDesc(fn ($c) => [$c['delivered'], $c['in_hand']])->take(self::COURIERS)->values()->all();
    }
}
