<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformHealth;
use Illuminate\Http\JsonResponse;

/**
 * Console : santé de la plateforme (services, sauvegardes, réglages de sécurité).
 */
class ConsoleHealthController extends Controller
{
    public function show(PlatformHealth $health): JsonResponse
    {
        return response()->json(['data' => ['checks' => $health->checks(), 'checked_at' => now()->toIso8601String()]]);
    }
}
