<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\ReturnSlips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Retours aux marchands : colis à rendre par marchand, confiés à un livreur ; la remise se fait par QR code.
 */
class ReturnSlipController extends Controller
{
    public function __construct(private readonly ReturnSlips $slips) {}

    /**
     * Colis à rendre résumés par marchand (les colis se chargent marchand par marchand : voir candidates).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);

        $summary = $this->slips->summary();
        $merchants = Merchant::with('pickupZone:id,name')->whereIn('id', $summary->pluck('merchant_id'))->get()->keyBy('id');
        $candidates = $summary->filter(fn ($row) => $merchants->has($row->merchant_id))->map(fn ($row) => [
            'merchant' => $this->merchantData($merchants[$row->merchant_id]),
            'total' => (int) $row->total,
            'held' => (int) $row->held,
            'legacy' => (int) $row->legacy,
            'oldest' => $row->oldest ? substr((string) $row->oldest, 0, 10) : null,
        ])->values();

        return response()->json([
            'candidates' => $candidates,
            'candidates_total' => $candidates->sum('total'),
        ]);
    }

    /** Colis à rendre d'un marchand (déplié dans Retours marchands) */
    public function candidates(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);
        $data = $request->validate(['merchant_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->slips->candidates($data['merchant_id'])->map(fn (Order $o) => $this->orderData($o))->values()]);
    }

    /**
     * Colis à rendre (compteur du menu, carte « Retours marchands ») : combien, dont à confier et confiés (ou en
     * route), chez combien d'e-commerçants, combien chez un livreur, plus ancien jour de livraison prévu.
     */
    public function count(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);
        $summary = $this->slips->summary();
        $oldest = $summary->pluck('oldest')->filter()->min();

        $returning = $this->slips->returningQuery()->count();
        $toAssign = $this->slips->toAssignQuery()->count();
        $waiting = (int) $summary->sum('total');

        return response()->json(['data' => [
            // Colis à rendre : à confier, confiés à un livreur, ou en route vers le marchand (même règle partout)
            'to_return' => $waiting + $returning,
            'to_assign' => $toAssign,
            'assigned' => $waiting - $toAssign + $returning,
            'merchants' => $summary->count(),
            'held' => (int) $summary->sum('held'),
            'oldest_days' => $oldest ? (int) Carbon::parse(substr((string) $oldest, 0, 10))->diffInDays(today()) : null,
        ]]);
    }

    /**
     * Confier à un livreur les colis à rendre d'un marchand (missions de retour). La remise se fait chez le marchand
     * en scannant le QR code du livreur ; il n'y a plus de bon de retour.
     */
    public function assign(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->where('company_id', $companyId)],
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $companyId)],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
        ], [], ['courier_id' => 'livreur', 'order_ids' => 'colis']);

        $count = $this->slips->assign($request->user(), Merchant::findOrFail($data['merchant_id']), Courier::findOrFail($data['courier_id']), $data['order_ids']);

        return response()->json(['data' => ['assigned' => $count]]);
    }

    private function authorizeDispatch(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->can(Permission::OrdersDispatch->value), 403);
    }

    private function merchantData(?Merchant $merchant): ?array
    {
        return $merchant ? [
            'id' => $merchant->id,
            'business_name' => $merchant->business_name,
            'phone' => $merchant->phone,
            'zone' => $merchant->pickupZone?->name,
            'address' => $merchant->pickup_address,
        ] : null;
    }

    private function orderData(Order $o): array
    {
        return [
            'id' => $o->id,
            'tracking_code' => $o->tracking_code,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'recipient_name' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'zone' => $o->deliveryZone?->name,
            'items_amount' => $o->items_amount,
            'incident' => $o->lastIncidentReason?->label,
            'attempts_count' => $o->attempts_count,
            'held_by' => $o->relationLoaded('holder') && $o->holder ? ['id' => $o->holder->id, 'name' => $o->holder->user?->name] : null,
            'return_courier' => $o->relationLoaded('returnCourier') && $o->returnCourier ? ['id' => $o->returnCourier->id, 'name' => $o->returnCourier->user?->name] : null,
            // Repris de l'ancienne application, et jour de livraison prévu (ancienneté)
            'legacy' => $o->legacy_id !== null,
            'delivery_day' => $o->delivery_scheduled_date?->toDateString() ?? $o->created_at?->toDateString(),
        ];
    }
}
