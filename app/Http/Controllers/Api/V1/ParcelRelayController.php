<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Models\ParcelRelay;
use App\Services\Couriers\ParcelRelays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Relais entre livreurs : écran Relais du livreur (remettre avec un code, recevoir en le saisissant) et relais du
 * jour pour le dispatch, qui peut valider à la place des livreurs.
 */
class ParcelRelayController extends Controller
{
    public function __construct(private readonly ParcelRelays $relays) {}

    /** Livreur : colis à remettre et à recevoir */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->relays->board($this->courier($request))]);
    }

    /** Livreur qui a les colis : prépare le relais et obtient le code à montrer */
    public function offer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_courier_id' => ['required', 'integer'],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [], ['order_ids' => 'colis']);

        $to = Courier::query()->findOrFail($data['to_courier_id']);
        $relay = $this->relays->offer($this->courier($request), $to, $data['order_ids'], $data['lat'] ?? null, $data['lng'] ?? null);

        return response()->json(['data' => [
            'id' => $relay->id,
            'code' => $relay->code,
            'expires_at' => $relay->expires_at,
            'order_ids' => $relay->order_ids,
        ]], 201);
    }

    /** Livreur qui reçoit : saisit le code et coche les colis reçus */
    public function confirm(Request $request, ParcelRelay $relay): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:4'],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [], ['order_ids' => 'colis']);

        $relay = $this->relays->confirm($this->courier($request), $relay, $data['code'], $data['order_ids'], $data['lat'] ?? null, $data['lng'] ?? null);

        return response()->json(['data' => [
            'id' => $relay->id,
            'received' => count($relay->received_ids ?? []),
            'missing' => count($relay->missing_ids ?? []),
        ]]);
    }

    /** Dispatch : relais à faire et relais faits aujourd'hui */
    public function index(): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        return response()->json(['data' => $this->relays->today(), 'meta' => ['awaiting' => ParcelRelays::awaitingCount()]]);
    }

    /** Dispatch : valide le relais à la place des livreurs (téléphone en panne), avec un motif */
    public function validateRelay(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate([
            'from_courier_id' => ['required', 'integer'],
            'to_courier_id' => ['required', 'integer'],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['order_ids' => 'colis', 'reason' => 'motif']);

        $relay = $this->relays->validateByDispatch(
            $request->user(),
            Courier::query()->findOrFail($data['from_courier_id']),
            Courier::query()->findOrFail($data['to_courier_id']),
            $data['order_ids'],
            $data['reason'],
        );

        return response()->json(['data' => ['id' => $relay->id, 'received' => count($relay->received_ids ?? [])]]);
    }

    private function courier(Request $request): Courier
    {
        return $request->user()->courier ?? abort(403, 'Profil livreur introuvable.');
    }
}
