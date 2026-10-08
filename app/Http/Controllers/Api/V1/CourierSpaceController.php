<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Http\Resources\V1\OrderAssignmentResource;
use App\Models\OrderAssignment;
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
            ->with(['order' => fn ($q) => $q->with(OrderController::LIST_RELATIONS)])
            ->orderByRaw("CASE type WHEN 'pickup' THEN 0 WHEN 'delivery' THEN 1 ELSE 2 END")
            ->orderBy('assigned_at')
            ->get();

        return OrderAssignmentResource::collection($assignments);
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

    public function updateStatus(Request $request): CourierResource
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
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

        $courier->save();

        return CourierResource::make($courier->load(['user', 'zones'])->loadCount('activeAssignments'));
    }
}
