<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CourierLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Http\Resources\V1\OrderAssignmentResource;
use App\Models\CourierMessage;
use App\Models\OrderAssignment;
use App\Services\Couriers\CourierTracker;
use App\Services\Orders\OrderDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Espace du livreur connecté : ses missions, sa disponibilité, sa position.
 */
class CourierSpaceController extends Controller
{
    public function missions(Request $request): AnonymousResourceCollection
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        $assignments = OrderAssignment::query()
            ->where('courier_id', $courier->id)
            ->when(
                $request->boolean('history'),
                fn ($q) => $q->whereNotIn('status', ['assigned', 'accepted', 'in_progress'])->whereDate('updated_at', today()),
                fn ($q) => $q->active(),
            )
            // Frais déjà déclarés par ce livreur sur la course
            ->with(['order' => fn ($q) => $q->with([
                ...OrderController::LIST_RELATIONS,
                'expenses' => fn ($e) => $e->active()->where('courier_id', $courier->id)->orderBy('id'),
            ])])
            ->orderByRaw("CASE type WHEN 'pickup' THEN 0 WHEN 'delivery' THEN 1 ELSE 2 END")
            ->orderBy('assigned_at')
            ->get();

        // Consignes de l'agence non lues, par course
        $unread = CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')
            ->whereIn('order_id', $assignments->pluck('order_id'))
            ->groupBy('order_id')->selectRaw('order_id, COUNT(*) AS total')->pluck('total', 'order_id');
        $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('unread_messages', (int) ($unread[$a->order_id] ?? 0)));

        return OrderAssignmentResource::collection($assignments)->additional(['meta' => [
            // Toutes consignes non lues (y compris sur des missions terminées)
            'unread_messages' => CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')->where('created_at', '>=', now()->subDays(2))->count(),
        ]]);
    }

    public function accept(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher): OrderAssignmentResource
    {
        $assignment = $dispatcher->respond($request->user(), $assignment, true);

        return OrderAssignmentResource::make($assignment->load(['order' => fn ($q) => $q->with(OrderController::LIST_RELATIONS)]));
    }

    public function refuse(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher): OrderAssignmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motif']);

        $assignment = $dispatcher->respond($request->user(), $assignment, false, $data['reason']);

        return OrderAssignmentResource::make($assignment);
    }

    public function updateStatus(Request $request, CourierTracker $tracker): CourierResource
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('is_available', $data)) {
            $courier->is_available = $data['is_available'];
        }

        if (isset($data['lat'], $data['lng'])) {
            $courier->forceFill([
                'current_lat' => $data['lat'],
                'current_lng' => $data['lng'],
                'last_location_at' => now(),
            ]);
        }

        $changed = $courier->isDirty(['is_available', 'current_lat', 'current_lng', 'last_location_at']);
        $courier->save();

        // Historique des trajets, pendant le service uniquement
        if (isset($data['lat'], $data['lng']) && $courier->is_available) {
            $tracker->record($courier, (float) $data['lat'], (float) $data['lng'], $data['accuracy'] ?? null);
        }

        // Carte des livreurs en direct (personnel de l'entreprise)
        if ($changed) {
            CourierLocationUpdated::dispatch($courier);
        }

        return CourierResource::make($courier->load(['user', 'zones'])->loadCount('activeAssignments'));
    }
}
