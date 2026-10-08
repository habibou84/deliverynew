<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Requests\V1\UpdateOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public const LIST_RELATIONS = [
        'merchant', 'pickupZone', 'deliveryZone', 'lastIncidentReason',
        'pickupCourier.user', 'deliveryCourier.user', 'returnCourier.user',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            // File d'attente du dispatch : à valider, à ramasser, à livrer, à retourner
            'queue' => ['nullable', Rule::in(['to_confirm', 'to_pickup', 'to_deliver', 'to_return', 'incidents'])],
            'merchant_id' => ['nullable', 'integer'],
            'courier_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $orders = Order::query()
            ->visibleTo($request->user())
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $request->input('status')))
            ->when($request->filled('queue'), fn ($q) => $this->applyQueue($q, $request->string('queue')))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('courier_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('pickup_courier_id', $request->integer('courier_id'))
                ->orWhere('delivery_courier_id', $request->integer('courier_id'))
                ->orWhere('return_courier_id', $request->integer('courier_id'))))
            ->when($request->filled('zone_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('delivery_zone_id', $request->integer('zone_id'))
                ->orWhere('pickup_zone_id', $request->integer('zone_id'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = trim($request->string('search'));
                $term = '%'.mb_strtolower($raw).'%';
                $phone = \App\Support\PhoneNumber::normalize($raw);
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(tracking_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(merchant_reference) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$term])
                    ->orWhere('recipient_phone', 'like', $phone ? "%{$phone}%" : $term));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 30));

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $merchant = Merchant::findOrFail($user->merchant_id ?? $data['merchant_id']);

        $source = $user->merchant_id !== null ? 'dashboard' : 'admin';
        $order = $this->orders->create($user, $merchant, $data, $source);

        return OrderResource::make($this->loadDetail($order))->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return OrderResource::make($this->loadDetail($order));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $order = $this->orders->update($request->user(), $order, $request->validated());

        return OrderResource::make($this->loadDetail($order));
    }

    public static function loadDetailRelations(Request $request): array
    {
        $isMerchant = $request->user()?->merchant_id !== null;

        return [
            ...self::LIST_RELATIONS,
            'events' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('visible_to_merchant', true))
                ->with(['incidentReason', 'attachments'])
                ->orderBy('id'),
            'assignments' => fn ($q) => $q->with(['courier.user', 'assignedBy'])->orderBy('id'),
        ];
    }

    private function loadDetail(Order $order): Order
    {
        return $order->fresh()->load(self::loadDetailRelations(request()));
    }

    private function applyQueue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'to_confirm' => $query->where('status', OrderStatus::Pending),
            'to_pickup' => $query->where('status', OrderStatus::Confirmed),
            'to_deliver' => $query->whereIn('status', OrderStatus::values([
                OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryFailed, OrderStatus::Rescheduled,
            ]))->where('return_requested', false)
                ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Delivery->value)),
            'to_return' => $query->where('return_requested', true)
                ->whereNotIn('status', OrderStatus::values([OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Returned])),
            'incidents' => $query->whereIn('status', OrderStatus::values([OrderStatus::DeliveryFailed, OrderStatus::Rescheduled])),
        };
    }
}
