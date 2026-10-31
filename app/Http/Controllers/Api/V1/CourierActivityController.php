<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\CourierActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Activité des livreurs : suivi du jour de toute l'équipe et missions du jour d'un livreur.
 */
class CourierActivityController extends Controller
{
    public function __construct(private readonly CourierActivity $activity) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);

        return response()->json($this->activity->today($request->user()->company, $request->input('date')));
    }

    public function show(Request $request, Courier $courier): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);

        return response()->json(['data' => $this->activity->missions($courier, $request->input('date'))]);
    }
}
