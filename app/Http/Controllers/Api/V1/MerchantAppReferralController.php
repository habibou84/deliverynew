<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantAppAccount;
use Illuminate\Http\JsonResponse;

/**
 * E-commerçants parrainés par l'entreprise sur Jibiat Marchand : abonnement et part de l'abonnement revenant à
 * l'entreprise, tels que Jibiat Marchand les rapporte (contrat merchant-app).
 */
class MerchantAppReferralController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = MerchantAppAccount::with('merchant:id,business_name')->where('referred', true)
            ->orderByDesc('earned_total')->orderBy('store_name')->get();

        return response()->json([
            'data' => $accounts->map(fn (MerchantAppAccount $a) => [
                'id' => $a->id,
                'merchant' => $a->merchant?->business_name,
                'store_name' => $a->store_name,
                'store_url' => $a->store_url,
                'plan_name' => $a->plan_name,
                'plan_status' => $a->plan_status,
                'commission_rate' => $a->commission_rate,
                'commission_ends_at' => $a->commission_ends_at?->toDateString(),
                'earned_total' => $a->earned_total,
                'earned_month' => $a->earned_month,
                'paid_total' => $a->paid_total,
                'reported_at' => $a->reported_at,
            ])->values(),
            'meta' => [
                'referred' => $accounts->count(),
                'paying' => $accounts->where('plan_status', 'active')->count(),
                'earned_month' => $accounts->sum('earned_month'),
                'earned_total' => $accounts->sum('earned_total'),
                'paid_total' => $accounts->sum('paid_total'),
                'due' => max(0, $accounts->sum('earned_total') - $accounts->sum('paid_total')),
                'rate' => (float) config('services.jibiat_marchand.referral_rate'),
                'months' => (int) config('services.jibiat_marchand.referral_months'),
            ],
        ]);
    }
}
