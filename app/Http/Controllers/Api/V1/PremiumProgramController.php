<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantAppAccount;
use App\Models\PremiumCard;
use App\Services\Premium\PremiumCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Programme Premium Jibiat Marchand de l'entreprise : cartes offertes, meilleurs marchands, carte automatique ;
 * et côté e-commerçant, sa carte ou ce qu'il lui reste pour la gagner.
 */
class PremiumProgramController extends Controller
{
    public function __construct(private readonly PremiumCards $premium) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);
        // Relu chez Jibiat Marchand au plus tous les quarts d'heure (état, boutique activée)
        $stale = PremiumCard::query()->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subMinutes(15)))->exists();
        if ($stale) {
            try {
                $this->premium->sync($company);
            } catch (Throwable) {
                // Facultatif : la page montre la dernière copie
            }
        }

        $settings = $this->premium->settings();
        $cards = PremiumCard::with(['merchant:id,business_name', 'offeredBy:id,name'])->latest('offered_at')->latest('id')->get();
        $referrals = MerchantAppAccount::query()->where('referred', true)->get(['earned_total', 'earned_month', 'plan_status']);

        return response()->json(['data' => [
            'program' => [
                'enabled' => $settings['enabled'],
                'configured' => PremiumCards::configured(),
                'funded_by' => $settings['funded_by'],
                'price' => $this->premium->price(),
                'conditions' => [
                    'min_delivered' => $settings['min_delivered'],
                    'window_days' => $settings['window_days'],
                    'min_success_rate' => $settings['min_success_rate'],
                    'min_client_days' => $settings['min_client_days'],
                    'max_debt_days' => $settings['max_debt_days'],
                ],
            ],
            'auto' => (bool) $company->premium_auto,
            'quota' => $this->premium->quota($company),
            'used' => $this->premium->used($company),
            'active' => $cards->whereIn('state', ['free', 'discount'])->whereNull('revoked_at')->count(),
            'cards' => $cards->map(fn (PremiumCard $c) => $this->premium->present($c))->values(),
            'ranking' => $this->premium->ranking($company, 30),
            'referrals' => [
                'stores' => $referrals->count(),
                'paying' => $referrals->where('plan_status', 'active')->count(),
                'earned_month' => (int) $referrals->sum('earned_month'),
                'earned_total' => (int) $referrals->sum('earned_total'),
                'rate' => (float) config('services.jibiat_marchand.referral_rate'),
                'months' => (int) config('services.jibiat_marchand.referral_months'),
            ],
        ]]);
    }

    public function offer(Request $request): JsonResponse
    {
        $data = $request->validate(['merchant_id' => ['required', 'integer']], [], ['merchant_id' => 'e-commerçant']);
        $merchant = Merchant::query()->findOrFail($data['merchant_id']);
        $card = $this->premium->offer($this->company($request), $merchant, 'manual', $request->user());

        return response()->json(['data' => $this->premium->present($card->load(['merchant:id,business_name', 'offeredBy:id,name']))], 201);
    }

    public function revoke(PremiumCard $card): JsonResponse
    {
        abort_if($card->revoked_at !== null, 422, 'Cette carte est déjà annulée.');

        return response()->json(['data' => $this->premium->present($this->premium->revoke($card)->load(['merchant:id,business_name', 'offeredBy:id,name']))]);
    }

    public function auto(Request $request): JsonResponse
    {
        $data = $request->validate(['auto' => ['required', 'boolean']], [], ['auto' => 'carte automatique']);
        $company = $this->company($request);
        $company->forceFill(['premium_auto' => $data['auto']])->save();

        return response()->json(['data' => ['auto' => (bool) $company->premium_auto]]);
    }

    /**
     * L'e-commerçant : sa carte, ou, si l'entreprise offre la carte automatique, où il en est. null sinon.
     */
    public function mine(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_if($merchant === null, 403, 'Réservé aux e-commerçants.');
        $company = Company::findOrFail($merchant->company_id);

        $card = PremiumCard::query()->where('merchant_id', $merchant->id)->whereNull('revoked_at')->latest('id')->first();
        if ($card !== null) {
            return response()->json(['data' => [
                'card' => [
                    'label' => $card->label,
                    'url' => $card->url,
                    'state' => $card->state,
                    'free_until' => $card->free_until?->toDateString(),
                    'store_name' => $card->store_name,
                    'store_url' => $card->store_url,
                ],
                'company' => $company->name,
            ]]);
        }
        if (! $this->premium->active() || ! $company->premium_auto || $this->premium->used($company) >= $this->premium->quota($company)) {
            return response()->json(['data' => null]);
        }

        $p = $this->premium->progress($merchant);
        $s = $this->premium->settings();

        return response()->json(['data' => [
            'card' => null,
            'company' => $company->name,
            'progress' => [
                'delivered' => $p['delivered'],
                'target' => $s['min_delivered'],
                'missing' => $p['missing_deliveries'],
                'window_days' => $s['window_days'],
                'success_rate' => $p['success_rate'],
                'min_success_rate' => $s['min_success_rate'],
                'client_days' => $p['client_days'],
                'min_client_days' => $s['min_client_days'],
                'late_debt' => $p['late_debt'],
                'checks' => $p['checks'],
            ],
        ]]);
    }

    private function company(Request $request): Company
    {
        return Company::findOrFail($request->user()->company_id);
    }
}
