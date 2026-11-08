<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LivePayment;
use App\Models\PlatformCredit;
use App\Services\Live\LiveStudio;
use App\Services\Payments\PayDunya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'enabled' => ['sometimes', 'boolean'], 'qr_whatsapp' => ['sometimes', 'boolean'], 'paid' => ['sometimes', 'boolean'],
            'free_upto' => $int(10000), 'tier2_upto' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'tier2_price' => $int(1000000),
            'tier3_upto' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'tier3_price' => $int(1000000), 'top_price' => $int(1000000),
            'free_orders' => $int(100000), 'min_credit' => $int(1000000),
            'cards_per_live' => $int(1000), 'welcome_cards' => $int(1000),
            'price_month' => $int(10000000), 'cards_per_month' => $int(10000),
            'pack_price' => $int(1000000), 'pack_cards' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'agency_share' => $int(100), 'retakes' => $int(20), 'cards_per_day' => $int(10000),
            'items_per_card' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'low_stock' => $int(1000), 'idle_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'risk_refusals' => ['sometimes', 'integer', 'min:1', 'max:100'], 'min_sales_range' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'monthly_ai_cap' => $int(100000000),
        ], [], ['tier2_upto' => 'palier 2', 'tier3_upto' => 'palier 3', 'free_upto' => 'gratuit jusqu\'à']);
        $next = [...$this->studio->settings(), ...$data];
        if (! ($next['free_upto'] < $next['tier2_upto'] && $next['tier2_upto'] < $next['tier3_upto'])) {
            throw ValidationException::withMessages(['tier2_upto' => 'Les paliers doivent se suivre : gratuit < palier 2 < palier 3.']);
        }
        $this->studio->updateSettings($data);

        return $this->show();
    }

    /**
     * Paiements en ligne des e-commerçants (PayDunya) et crédit des agences (leur part, déduite de leurs factures).
     */
    public function payments(): JsonResponse
    {
        $payments = LivePayment::withoutGlobalScopes()->with(['merchant' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'business_name'), 'company:id,name'])
            ->latest('id')->limit(100)->get();
        $credits = PlatformCredit::query()->selectRaw('company_id, SUM(amount) as balance, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as earned')
            ->groupBy('company_id')->with('company:id,name')->get()
            ->map(fn (PlatformCredit $c) => ['company_id' => $c->company_id, 'company' => $c->company?->name, 'balance' => (int) $c->balance, 'earned' => (int) $c->earned])
            ->sortByDesc('balance')->values();

        return response()->json(['data' => [
            'configured' => PayDunya::configured(),
            'mode' => config('payments.paydunya.mode'),
            'month' => [
                'paid' => (int) LivePayment::withoutGlobalScopes()->where('status', LivePayment::PAID)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'count' => LivePayment::withoutGlobalScopes()->where('status', LivePayment::PAID)->where('paid_at', '>=', now()->startOfMonth())->count(),
            ],
            'payments' => $payments->map(fn (LivePayment $p) => [
                'id' => $p->id,
                'date' => $p->created_at->toIso8601String(),
                'company' => $p->company?->name,
                'merchant' => $p->merchant?->business_name,
                'kind' => $p->kind,
                'amount' => $p->amount,
                'status' => $p->status,
                'method' => $p->method,
                'error' => $p->error,
            ]),
            'credits' => $credits,
        ]]);
    }

    /** Versement du crédit d'une agence (fait en dehors de Jibiat, par Wave ou PayDunya) : enregistré ici */
    public function payout(Request $request, Company $company): JsonResponse
    {
        $balance = PlatformCredit::balance($company->id);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:'.max(1, $balance)], 'note' => ['nullable', 'string', 'max:120']]);
        PlatformCredit::create([
            'company_id' => $company->id,
            'amount' => -$data['amount'],
            'reason' => PlatformCredit::PAYOUT,
            'label' => 'Versé par Jibiat'.(filled($data['note'] ?? null) ? ' · '.$data['note'] : ''),
            'created_by' => $request->user()->id,
        ]);

        return $this->payments();
    }

    /** Entreprise : l'outil live, son prix pour l'e-commerçant, et le choix de l'offrir */
    public function companyOfferShow(Request $request): JsonResponse
    {
        $s = $this->studio->settings();

        return response()->json(['data' => [
            'live_offered' => (bool) $request->user()->company->live_offered,
            'enabled' => (bool) $s['enabled'],
            'tiers' => $this->studio->tiers(),
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
