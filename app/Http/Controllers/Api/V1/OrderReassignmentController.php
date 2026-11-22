<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\OrderReassignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Colis en retour réaffecté à une autre course du même e-commerçant (même article) : courses qui attendent cet
 * article, colis en retour qui le contiennent, réaffectation, nouvelle étiquette confirmée.
 */
class OrderReassignmentController extends Controller
{
    public function __construct(private readonly OrderReassignments $reassignments) {}

    /** Pour un colis en retour : les courses du même e-commerçant qui attendent le même article */
    public function candidates(Order $order): JsonResponse
    {
        Gate::authorize('update', $order);

        return response()->json(['data' => $this->reassignments->candidatesFor($order)->map(fn (Order $o) => self::summary($o))->values()]);
    }

    /** Pour une course qu'on enregistre (ou en attente) : les colis en retour qui contiennent ces articles */
    public function returning(Request $request): JsonResponse
    {
        // Articles envoyés en JSON dans l'adresse (GET)
        if (is_string($request->input('items'))) {
            $request->merge(['items' => json_decode($request->input('items'), true) ?: []]);
        }
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.label' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        if ($user->merchant_id === null) {
            Gate::authorize('dispatch', Order::class);
        }
        $merchant = Merchant::query()->findOrFail($user->merchant_id ?? $data['merchant_id'] ?? 0);

        $list = $this->reassignments->returningFor($merchant, $data['items'] ?? [], $data['description'] ?? null, $data['order_id'] ?? null);

        return response()->json(['data' => $list->map(fn (Order $o) => self::summary($o))->values()]);
    }

    /** Le colis de cette course en retour part avec la course order_id */
    public function reassign(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['order_id' => ['required', 'integer']], [], ['order_id' => 'course']);
        $target = Order::query()->findOrFail($data['order_id']);
        Gate::authorize('update', $order);
        Gate::authorize('update', $target);

        $target = $this->reassignments->reassign($request->user(), $order, $target);

        return OrderResource::make($target->load(OrderController::loadDetailRelations($request)));
    }

    /** Nouvelle étiquette collée : scan (code complet) ou 4 derniers caractères */
    public function confirmLabel(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']], [], ['code' => 'code']);
        $user = $request->user();
        $courierId = $user->courier?->id;
        $courierHasIt = $courierId !== null && ($order->held_by_courier_id === $courierId || $order->assignments()->active()->where('courier_id', $courierId)->exists());
        if (! ($user->can('dispatch', Order::class) || $courierHasIt || Gate::allows('update', $order))) {
            abort(403);
        }

        $order = $this->reassignments->confirmLabel($user, $order, $data['code']);

        return OrderResource::make($order->load(OrderController::loadDetailRelations($request)));
    }

    /** @return array<string, mixed> */
    public static function summary(Order $o): array
    {
        return [
            'id' => $o->id,
            'tracking_code' => $o->tracking_code,
            'status' => $o->status->value,
            'status_label' => $o->statusLabel(),
            'recipient_name' => $o->recipient_name,
            'zone' => $o->deliveryZone?->fullName() ?? $o->deliveryZone?->name,
            'content' => $o->items->isNotEmpty() ? $o->items->map(fn ($i) => "{$i->quantity} × {$i->label}")->implode(', ') : $o->description,
            'items_amount' => (int) $o->items_amount,
            'created_at' => $o->created_at,
        ];
    }
}
