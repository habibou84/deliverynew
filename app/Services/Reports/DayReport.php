<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Point du jour de la page Courses : courses prévues et livrées ce jour, recette de livraison, argent encaissé chez les
 * clients (déjà à la caisse ou encore chez les livreurs) et montant à reverser aux e-commerçants.
 *
 * Les courses prévues sont celles dont le jour de livraison est ce jour ; les livrées sont celles livrées ce jour
 * (quel que soit leur jour prévu). Les montants portent sur les livrées du jour.
 */
class DayReport
{
    /** @return array<string, mixed> */
    public function build(Company $company, string $day, bool $withMoney): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($day, $tz)->startOfDay();
        $orders = fn () => Order::withoutGlobalScopes()->where('company_id', $company->id);

        $planned = $orders()->forPeriod($day, $day, 'delivery')->get(['id', 'status']);
        $count = fn (array $statuses) => $planned->whereIn('status', $statuses)->count();
        $delivered = $orders()->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start->utc(), $start->endOfDay()->utc()])
            ->get(['id', 'merchant_id', 'is_shipping']);

        $report = [
            'day' => $start->toDateString(),
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
                'cancelled' => $count([OrderStatus::Cancelled, OrderStatus::Rejected]),
            ],
            'money' => null,
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
}
