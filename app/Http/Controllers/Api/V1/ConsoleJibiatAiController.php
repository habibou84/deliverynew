<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Ai\JibiatAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : Jibiat IA. Interrupteur général, prix de chaque action en points, cours du dollar (coût réel), coupure par
 * entreprise, et pour chaque entreprise : points utilisés, coût réel, plafond (réglé dans chaque formule).
 */
class ConsoleJibiatAiController extends Controller
{
    public function __construct(private readonly JibiatAi $ai) {}

    public function show(): JsonResponse
    {
        $companies = $this->ai->overview();

        return response()->json(['data' => [
            'settings' => $this->ai->settings(),
            'features' => JibiatAi::FEATURES,
            'model' => (string) config('messaging.ai.model'),
            'companies' => $companies->values(),
            'totals' => [
                'used' => $companies->sum('used'),
                'cost' => $companies->sum('cost'),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.enabled' => ['sometimes', 'boolean'],
            'settings.points_message' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'settings.points_photo' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'settings.usd_rate' => ['sometimes', 'integer', 'min:100', 'max:2000'],
            // Pack de points vendu en plus (prépayé)
            'settings.pack_points' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'settings.pack_price' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
        ]);
        $this->ai->updateSettings($data['settings']);

        return $this->show();
    }

    /** Couper ou rallumer l'IA pour une entreprise */
    public function company(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['disabled' => ['required', 'boolean']]);
        $this->ai->setDisabled($company, $data['disabled']);

        return $this->show();
    }
}
