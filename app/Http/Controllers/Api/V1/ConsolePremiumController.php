<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PremiumCard;
use App\Models\PremiumLevel;
use App\Services\Premium\PremiumCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console : réglages du programme Premium Jibiat Marchand (qui paie, prix, cartes par entreprise, conditions de la
 * carte automatique) et cartes de chaque entreprise.
 */
class ConsolePremiumController extends Controller
{
    public function __construct(private readonly PremiumCards $premium) {}

    public function show(): JsonResponse
    {
        $counts = PremiumCard::query()->whereNull('revoked_at')->groupBy('company_id')->selectRaw('company_id, COUNT(*) AS total')->pluck('total', 'company_id');
        $levels = PremiumLevel::query()->withCount('merchants')->orderBy('rank')->get()->groupBy('company_id');
        $active = PremiumCard::query()->whereNull('revoked_at')->whereIn('state', ['free', 'discount'])->groupBy('company_id')->selectRaw('company_id, COUNT(*) AS total')->pluck('total', 'company_id');

        return response()->json(['data' => [
            'settings' => $this->premium->settings(),
            'plans' => $this->premium->plans(),
            'plan_features' => PremiumCards::PLAN_FEATURES,
            'configured' => PremiumCards::configured(),
            'funders' => PremiumCards::FUNDERS,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug', 'premium_auto', 'premium_quota'])
                ->map(fn (Company $c) => [
                    'levels' => ($levels[$c->id] ?? collect())->map(fn (PremiumLevel $l) => [
                        'name' => $l->name,
                        'merchants' => $l->merchants_count,
                        'delivery_discount_percent' => $l->delivery_discount_percent,
                        'jm_plan' => $l->jm_plan,
                        'jm_free_months' => $l->jm_free_months,
                    ])->values(),
                    'id' => $c->id,
                    'name' => $c->name,
                    'auto' => (bool) $c->premium_auto,
                    'quota' => $c->premium_quota,
                    'effective_quota' => $this->premium->quota($c),
                    'used' => (int) ($counts[$c->id] ?? 0),
                    'active' => (int) ($active[$c->id] ?? 0),
                ])->values(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'funded_by' => ['required', Rule::in(array_keys(PremiumCards::FUNDERS))],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'min_delivered' => ['required', 'integer', 'min:1', 'max:10000'],
            'window_days' => ['required', 'integer', 'min:7', 'max:365'],
            'min_success_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_client_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'max_debt_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_levels' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'max_delivery_discount' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'plans' => ['sometimes', 'array'],
            'plans.*.allowed' => ['required_with:plans', 'boolean'],
            'plans.*.max_months' => ['required_with:plans', 'integer', 'min:0', 'max:36'],
            'plans.*.max_discount' => ['required_with:plans', 'integer', 'min:0', 'max:90'],
            'plans.*.price' => ['required_with:plans', 'integer', 'min:0', 'max:10000000'],
        ], [], [
            'enabled' => 'programme activé',
            'quota' => 'cartes par entreprise',
            'funded_by' => 'qui paie les cartes',
            'price' => 'prix d\'une carte',
            'min_delivered' => 'courses livrées',
            'window_days' => 'période',
            'min_success_rate' => 'taux de réussite',
            'min_client_days' => 'ancienneté',
            'max_debt_days' => 'retard d\'argent',
        ]);
        if (isset($data['plans'])) {
            $this->premium->updatePlans(array_intersect_key($data['plans'], PremiumCards::PLAN_DEFAULTS));
            unset($data['plans']);
        }
        $this->premium->updateSettings($data);

        return $this->show();
    }

    public function company(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['quota' => ['present', 'nullable', 'integer', 'min:0', 'max:1000']], [], ['quota' => 'cartes de l\'entreprise']);
        $company->forceFill(['premium_quota' => $data['quota']])->save();

        return response()->json(['data' => ['quota' => $company->premium_quota, 'effective_quota' => $this->premium->quota($company)]]);
    }
}
