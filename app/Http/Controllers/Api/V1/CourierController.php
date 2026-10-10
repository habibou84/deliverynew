<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Models\Courier;
use App\Services\Couriers\CourierTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Profils livreurs. Les comptes sont créés via /users (rôle « courier ») :
 * le profil est créé automatiquement.
 */
class CourierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'zone_id' => ['nullable', 'integer'],
            'available' => ['nullable', 'boolean'],
        ]);

        $couriers = Courier::query()
            ->with(['user', 'zones', 'payPlan'])
            ->withCount('activeAssignments')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->when($request->has('available'), fn ($q) => $q->where('is_available', $request->boolean('available')))
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas('zones', fn ($q) => $q->where('zones.id', $request->integer('zone_id'))))
            ->get()
            ->sortBy(fn (Courier $c) => [! $c->is_available, $c->active_assignments_count, $c->user->name])
            ->values();

        return CourierResource::collection($couriers);
    }

    /**
     * Carte des livreurs : dernière position connue et missions en cours de chaque livreur actif.
     */
    public function map(): JsonResponse
    {
        $couriers = Courier::query()
            ->with(['user', 'activeAssignments' => fn ($q) => $q->with(['order.pickupZone', 'order.deliveryZone'])->orderBy('id')])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->user->name,
                'phone' => $c->user->phone,
                'vehicle_type' => $c->vehicle_type,
                'is_available' => $c->is_available,
                'lat' => $c->current_lat,
                'lng' => $c->current_lng,
                'last_location_at' => $c->last_location_at,
                'missions' => $c->activeAssignments->map(fn ($a) => [
                    'order_id' => $a->order_id,
                    'tracking_code' => $a->order?->tracking_code,
                    'type' => $a->type->value,
                    'type_label' => $a->type->label(),
                    'status' => $a->status->value,
                    'zone_name' => $a->type->value === 'pickup' ? $a->order?->pickupZone?->name : $a->order?->deliveryZone?->name,
                ])->values(),
            ])
            ->sortBy(fn ($c) => [$c['lat'] === null, ! $c['is_available'], $c['name']])
            ->values();

        return response()->json(['data' => $couriers]);
    }

    /**
     * Trajet d'une journée : tracé, étapes des courses (ramassé, livré, échec…) et résumé.
     */
    public function track(Request $request, Courier $courier, CourierTracker $tracker): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);

        $courier->loadMissing('user');

        return response()->json(['data' => [
            'courier' => ['id' => $courier->id, 'name' => $courier->user?->name, 'phone' => $courier->user?->phone],
            ...$tracker->day($courier, $request->filled('date') ? Carbon::parse($request->string('date')) : today()),
        ]]);
    }

    public function show(Courier $courier): CourierResource
    {
        return CourierResource::make($courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments'));
    }

    public function update(Request $request, Courier $courier): CourierResource
    {
        $data = $request->validate([
            'vehicle_type' => ['sometimes', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Plan partagé de l'entreprise, ou plan personnel de ce livreur ; null = plan par défaut
            'pay_plan_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pay_plans', 'id')->where('company_id', $courier->company_id)
                ->where(fn ($q) => $q->whereNull('courier_id')->orWhere('courier_id', $courier->id))],
            'is_available' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $courier->company_id)],
        ]);

        // La rémunération relève des réglages de l'entreprise
        abort_if(array_key_exists('pay_plan_id', $data) && ! $request->user()->can(Permission::SettingsManage->value), 403, 'Seul un administrateur peut changer le plan de paie.');

        $courier->update(collect($data)->except('zone_ids')->all());

        if (array_key_exists('zone_ids', $data)) {
            $courier->zones()->sync($data['zone_ids']);
        }

        return CourierResource::make($courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments'));
    }
}
