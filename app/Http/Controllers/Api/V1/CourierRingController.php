<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierDevice;
use App\Models\OrderAssignment;
use App\Services\Couriers\MissionRing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sonnerie des missions dans l'application Android du livreur : enregistrement du téléphone, écran « appel
 * entrant » (les missions qui sonnent) et réponse du livreur, qui arrête la sonnerie et le rappel.
 */
class CourierRingController extends Controller
{
    public function __construct(private readonly MissionRing $ring) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);
        $user = $request->user();
        // Un téléphone passé d'un livreur à l'autre ne sonne plus pour l'ancien
        CourierDevice::query()->updateOrCreate(['token' => $data['token']], [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'platform' => $data['platform'] ?? 'android',
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => ['registered' => true]]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        CourierDevice::query()->where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['data' => ['registered' => false]]);
    }

    /** Écran « appel entrant » : les missions qui sonnent, et ce que le livreur peut y répondre */
    public function show(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $assignments = OrderAssignment::query()->where('courier_id', $courier->id)->whereKey($this->ids($request))
            ->with(['order' => fn ($q) => $q->with(['merchant', 'pickupZone', 'deliveryZone'])])->orderBy('id')->get();
        if ($assignments->isEmpty()) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            ...$this->ring->describe($assignments, max(1, min(2, $request->integer('attempt', 1)))),
            'missions' => $assignments->map(fn (OrderAssignment $a) => [
                'id' => $a->id,
                'order_id' => $a->order_id,
                'type' => $a->type->value,
                'status' => $a->status->value,
                'tracking_code' => $a->order?->tracking_code,
            ])->values(),
        ]]);
    }

    public function answer(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json(['data' => ['answered' => $this->ring->answer($courier, $this->ids($request))]]);
    }

    private function courier(Request $request): Courier
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        return $courier;
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $request->validate(['ids' => ['required', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/']]);

        return array_values(array_unique(array_map('intval', explode(',', $request->string('ids')))));
    }
}
