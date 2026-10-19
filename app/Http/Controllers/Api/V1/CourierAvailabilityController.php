<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\CourierAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disponibilité des livreurs (dispatch) : qui a fini sa tournée, où, et quels colis
 * ramasser près de lui ; mission ramassage confiée d'un coup.
 */
class CourierAvailabilityController extends Controller
{
    public function index(Request $request, CourierAvailability $availability): JsonResponse
    {
        return response()->json([
            'data' => $availability->board($request->user()->company),
            'meta' => ['soon' => CourierAvailability::SOON, 'radius_km' => CourierAvailability::RADIUS_KM],
        ]);
    }

    public function show(Courier $courier, CourierAvailability $availability): JsonResponse
    {
        return response()->json(['data' => $availability->forCourier($courier)]);
    }

    public function pickupMission(Request $request, Courier $courier, CourierAvailability $availability): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct'],
        ], [], ['order_ids' => 'colis']);

        return response()->json(['data' => $availability->assignPickupMission($request->user(), $courier, $data['order_ids'])]);
    }
}
