<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Live\LiveStudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : outil live des e-commerçants (prix, fiches IA, règles du live, suivi du mois). Côté entreprise : offrir
 * le live à ses e-commerçants.
 */
class ConsoleLiveController extends Controller
{
    public function __construct(private readonly LiveStudio $studio) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => $this->studio->settings(),
            'overview' => $this->studio->overview(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $int = fn (int $max) => ['sometimes', 'integer', 'min:0', "max:{$max}"];
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'merchant_whatsapp' => ['sometimes', 'boolean'],
            'price_live' => $int(1000000), 'cards_per_live' => $int(1000),
            'price_month' => $int(10000000), 'cards_per_month' => $int(10000),
            'pack_price' => $int(1000000), 'pack_cards' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'trial_lives' => $int(100), 'trial_cards' => $int(1000),
            'agency_share' => $int(100), 'retakes' => $int(20), 'cards_per_day' => $int(10000),
            'items_per_card' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'low_stock' => $int(1000), 'idle_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'risk_refusals' => ['sometimes', 'integer', 'min:1', 'max:100'], 'min_sales_range' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'monthly_ai_cap' => $int(100000000),
        ]);
        $this->studio->updateSettings($data);

        return $this->show();
    }

    /** Entreprise : l'outil live, son prix pour l'e-commerçant, et le choix de l'offrir */
    public function companyOfferShow(Request $request): JsonResponse
    {
        $s = $this->studio->settings();

        return response()->json(['data' => [
            'live_offered' => (bool) $request->user()->company->live_offered,
            'enabled' => (bool) $s['enabled'],
            'price_live' => $s['price_live'],
            'price_month' => $s['price_month'],
        ]]);
    }

    /** Entreprise : offrir le live à ses e-commerçants (elle paie à leur place) */
    public function companyOffer(Request $request): JsonResponse
    {
        $data = $request->validate(['live_offered' => ['required', 'boolean']]);
        $company = $request->user()->company;
        $company->forceFill($data)->save();

        return response()->json(['data' => ['live_offered' => $company->live_offered]]);
    }
}
