<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\CourierActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Activité des livreurs : suivi d'un jour (passé, aujourd'hui ou à venir) de toute l'équipe et missions de ce jour d'un livreur.
 */
class CourierActivityController extends Controller
{
    /** Jours à venir consultables (ce qui est déjà prévu) */
    public const FUTURE_DAYS = 30;

    public function __construct(private readonly CourierActivity $activity) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(self::FUTURE_DAYS)->toDateString()]]);

        return response()->json($this->activity->today($request->user()->company, $request->input('date')));
    }

    public function show(Request $request, Courier $courier): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(self::FUTURE_DAYS)->toDateString()]]);

        return response()->json(['data' => $this->activity->missions($courier, $request->input('date'))]);
    }
}
