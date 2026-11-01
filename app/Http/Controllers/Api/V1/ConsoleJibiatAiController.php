<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\JibiatAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : Jibiat IA. Niveaux (prix, volume, limites, plafond de coût), réglages (prix d'une course en plus, essai,
 * mois payés à l'année, cours du dollar), et ce que chaque entreprise paie face au coût réel de l'IA.
 */
class ConsoleJibiatAiController extends Controller
{
    public function __construct(private readonly JibiatAi $ai) {}

    public function show(): JsonResponse
    {
        $companies = $this->ai->overview();

        return response()->json(['data' => [
            'tiers' => $this->ai->tiers(),
            'settings' => $this->ai->settings(),
            'features' => JibiatAi::FEATURES,
            'companies' => $companies->values(),
            'totals' => [
                'orders' => $companies->sum('orders'),
                'paid' => $companies->sum('paid'),
                'cost' => $companies->sum('cost'),
                'margin' => $companies->sum('margin'),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $tierRules = [];
        foreach (array_keys(JibiatAi::TIER_DEFAULTS) as $key) {
            $tierRules["tiers.{$key}.price"] = ['sometimes', 'integer', 'min:0', 'max:10000000'];
            $tierRules["tiers.{$key}.orders"] = ['sometimes', 'integer', 'min:1', 'max:10000000'];
            $tierRules["tiers.{$key}.dispatch_per_day"] = ['sometimes', 'integer', 'min:0', 'max:96'];
            $tierRules["tiers.{$key}.voice_minutes"] = ['sometimes', 'integer', 'min:0', 'max:100000'];
            $tierRules["tiers.{$key}.cost_cap"] = ['sometimes', 'integer', 'min:0', 'max:10000000'];
            $tierRules["tiers.{$key}.active"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate([
            ...$tierRules,
            'tiers' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'settings.overage_per_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'settings.trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'settings.yearly_months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'settings.usd_rate' => ['sometimes', 'integer', 'min:100', 'max:2000'],
        ]);
        if (isset($data['tiers'])) {
            $this->ai->updateTiers($data['tiers']);
        }
        if (isset($data['settings'])) {
            $this->ai->updateSettings($data['settings']);
        }

        return $this->show();
    }
}
