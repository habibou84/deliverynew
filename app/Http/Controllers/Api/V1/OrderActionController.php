<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\TransitionOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderActionController extends Controller
{
    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderService $orders,
        private readonly OrderJournal $journal,
    ) {}

    public function transition(TransitionOrderRequest $request, Order $order): OrderResource
    {
        $data = $request->validated();

        $order = $this->workflow->transition(
            $request->user(),
            $order,
            OrderStatus::from($data['status']),
            $data,
        );

        return $this->detail($request, $order);
    }

    public function assign(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $this->validateAssignment($request);

        $this->dispatcher->assign(
            $request->user(),
            $order,
            AssignmentType::from($data['type']),
            Courier::findOrFail($data['courier_id']),
        );

        return $this->detail($request, $order);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $this->validateAssignment($request, [
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->dispatcher->assignMany(
            $request->user(),
            $data['order_ids'],
            AssignmentType::from($data['type']),
            Courier::findOrFail($data['courier_id']),
        );

        return response()->json(['data' => $result]);
    }

    public function note(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('addNote', $order);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'visible_to_merchant' => ['sometimes', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->orders->addNote(
            $request->user(),
            $order,
            $data['note'],
            $data['visible_to_merchant'] ?? true,
            $data['lat'] ?? null,
            $data['lng'] ?? null,
        );

        return response()->json(['message' => 'Note ajoutée.'], 201);
    }

    public function requestReturn(Request $request, Order $order): OrderResource
    {
        Gate::authorize('requestReturn', $order);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $this->orders->requestReturn($request->user(), $order, $data['note'] ?? null);

        return $this->detail($request, $order);
    }

    /**
     * Photo de preuve (ramassage, livraison, incident) prise par le livreur ou le personnel.
     */
    public function storeAttachment(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('addAttachment', $order);

        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'],
            'type' => ['required', Rule::in(['photo_pickup', 'photo_delivery', 'photo_incident', 'document'])],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['file' => 'photo']);

        $attachment = DB::transaction(function () use ($request, $order, $data) {
            $event = $this->journal->record($order, $request->user(), OrderEventType::ProofAdded, [
                'note' => $data['note'] ?? null,
                'meta' => ['attachment_type' => $data['type']],
            ]);

            $path = $request->file('file')->store("orders/{$order->id}", 'local');

            return OrderAttachment::create([
                'order_id' => $order->id,
                'event_id' => $event->id,
                'type' => $data['type'],
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $request->file('file')->getMimeType(),
                'uploaded_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => [
            'id' => $attachment->id,
            'type' => $attachment->type,
            'url' => route('v1.orders.attachments.show', [$order->id, $attachment->id], false),
        ]], 201);
    }

    public function showAttachment(Order $order, OrderAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $order);
        abort_unless($attachment->order_id === $order->id, 404);

        return Storage::disk($attachment->disk)->response($attachment->path);
    }

    private function validateAssignment(Request $request, array $extra = []): array
    {
        return $request->validate([
            ...$extra,
            'type' => ['required', Rule::enum(AssignmentType::class)],
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ], [], ['courier_id' => 'livreur', 'type' => 'type de mission']);
    }

    private function detail(Request $request, Order $order): OrderResource
    {
        return OrderResource::make($order->fresh()->load(OrderController::loadDetailRelations($request)));
    }
}
