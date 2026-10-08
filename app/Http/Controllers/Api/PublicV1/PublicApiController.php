<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Resources\PublicV1\PublicOrderResource;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ZoneResource;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Zone;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use App\Services\Pricing\PricingService;
use App\Services\Reports\OrderSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * API publique d'un marchand (/api/public/v1), authentifiée par clé API.
 * Mêmes règles que l'application du marchand : la clé agit au nom d'un de ses comptes.
 */
class PublicApiController extends Controller
{
    private const RELATIONS = ['deliveryZone', 'items', 'lastIncidentReason'];

    public function zones(): AnonymousResourceCollection
    {
        return ZoneResource::collection(Zone::query()->active()->with('parent')->orderBy('name')->get());
    }

    public function hubs(): JsonResponse
    {
        return response()->json(['data' => Hub::query()->active()->orderBy('name')->get()
            ->map(fn (Hub $h) => ['id' => $h->id, 'name' => $h->name, 'zone_id' => $h->zone_id])]);
    }

    public function quote(Request $request, PricingService $pricing): JsonResponse
    {
        return app(QuoteController::class)($request, $pricing);
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            'merchant_reference' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $this->query($request)
            ->with(self::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $request->input('status')))
            ->when($request->filled('merchant_reference'), fn ($q) => $q->where('merchant_reference', $request->string('merchant_reference')))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', $request->date('updated_since')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($request->integer('per_page', 30));

        return PublicOrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request, OrderService $service): JsonResponse
    {
        $user = $request->user();
        $order = $service->create($user, Merchant::findOrFail($user->merchant_id), $request->validated(), 'api');

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $trackingCode): PublicOrderResource
    {
        return PublicOrderResource::make($this->find($request, $trackingCode)->load([
            ...self::RELATIONS,
            'events' => fn ($q) => $q->where('visible_to_merchant', true)->with('incidentReason')->orderBy('id'),
        ]));
    }

    public function cancel(Request $request, string $trackingCode, OrderWorkflow $workflow): PublicOrderResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $order = $workflow->transition($request->user(), $this->find($request, $trackingCode), OrderStatus::Cancelled, [
            'cancel_reason' => $data['reason'] ?? null,
        ]);

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS));
    }

    public function summary(Request $request, OrderSummary $summary): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $summary->build(
            $this->query($request),
            $request->date('from') ?? today()->startOfMonth(),
            $request->date('to') ?? today(),
        )]);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200'], 'search' => ['nullable', 'string', 'max:100']]);

        $products = Product::query()
            ->where('merchant_id', $request->user()->merchant_id)
            ->where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($request->string('search')).'%']))
            ->with('levels.location.hub')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ProductResource::collection($products);
    }

    private function query(Request $request): Builder
    {
        return Order::query()->where('merchant_id', $request->user()->merchant_id);
    }

    private function find(Request $request, string $trackingCode): Order
    {
        return $this->query($request)->where('tracking_code', mb_strtoupper($trackingCode))->firstOrFail();
    }
}
