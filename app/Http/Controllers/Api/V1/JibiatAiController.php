<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\JibiatAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bloc « Jibiat IA » de « Mon abonnement » : points du mois (compris dans la formule), consommation. Un ancien
 * abonnement à part peut encore être arrêté ou continué.
 */
class JibiatAiController extends Controller
{
    public function __construct(private readonly JibiatAi $ai) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($request->user()->company->loadMissing('plan'))]);
    }

    public function stop(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($this->ai->stop($request->user()->company)->loadMissing('plan'))]);
    }

    public function resume(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($this->ai->resume($request->user()->company)->loadMissing('plan'))]);
    }
}
