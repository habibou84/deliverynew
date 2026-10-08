<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    /**
     * Point détaillé sur une période : volumes par état et montants.
     * Un marchand ne voit que ses courses ; le personnel peut filtrer par marchand.
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today();

        $orders = Order::query()
            ->visibleTo($request->user())
            ->when(isset($data['merchant_id']) && $request->user()->merchant_id === null, fn ($q) => $q->where('merchant_id', $data['merchant_id']))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get(['id', 'status', 'fee_payer', 'delivery_fee', 'surcharges_total', 'cod_amount', 'collected_amount', 'return_requested']);

        $count = fn (array $statuses) => $orders->whereIn('status', $statuses)->count();
        $delivered = $orders->where('status', OrderStatus::Delivered);

        $collected = $delivered->sum('collected_amount');

        // Frais retenus (livraisons et retours), tels qu'inscrits au grand livre du marchand
        $fees = -(int) MerchantLedgerEntry::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value])
            ->sum('amount');

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'counts' => [
                'total' => $orders->count(),
                'delivered' => $delivered->count(),
                'in_progress' => $count([
                    OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
                    OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned, OrderStatus::OutForDelivery,
                ]),
                'failed' => $count([OrderStatus::DeliveryFailed]),
                'rescheduled' => $count([OrderStatus::Rescheduled]),
                'returning' => $count([OrderStatus::ReturnAssigned, OrderStatus::Returning]),
                'returned' => $count([OrderStatus::Returned]),
                'cancelled' => $count([OrderStatus::Cancelled, OrderStatus::Rejected]),
            ],
            'amounts' => [
                'collected' => $collected,
                'fees' => $fees,
                'net_to_merchant' => $collected - $fees,
            ],
            'delivery_rate' => $orders->count() > 0 ? round($delivered->count() / $orders->count() * 100, 1) : null,
            'by_status' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [
                $s->value => $orders->where('status', $s)->count(),
            ]),
        ]]);
    }
}
