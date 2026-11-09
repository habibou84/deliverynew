<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Live;
use App\Models\LivePayment;
use App\Models\Merchant;
use App\Models\PlatformCredit;
use App\Services\Live\LiveStudio;
use App\Services\Payments\PayDunya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'pack_price' => $int(1000000), 'pack_cards' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'offered_packs_per_month' => $int(100),
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
                // Par type : recharges du crédit live, forfaits, packs de fiches IA
                'by_kind' => LivePayment::withoutGlobalScopes()->where('status', LivePayment::PAID)->where('paid_at', '>=', now()->startOfMonth())
                    ->selectRaw('kind, COUNT(*) AS n, SUM(amount) AS total')->groupBy('kind')->get()
                    ->mapWithKeys(fn ($r) => [$r->kind => ['count' => (int) $r->n, 'total' => (int) $r->total]]),
                // Lives comptés au palier : commandes, dont offertes, et prix pris (crédit, reversement ou agence)
                'billed' => (fn ($q) => [
                    'lives' => (clone $q)->count(),
                    'orders' => (int) (clone $q)->sum('billed_orders'),
                    'offered' => (int) (clone $q)->sum('billed_offered'),
                    'amount' => (int) (clone $q)->sum('billed_amount'),
                    'by_agency' => (clone $q)->where('plan', 'offered')->count(),
                ])(Live::withoutGlobalScopes()->whereIn('plan', ['tier', 'offered'])->where('billed_at', '>=', now()->startOfMonth())),
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

    /** E-commerçants à qui offrir des lives : recherche par nom ou numéro */
    public function merchants(Request $request): JsonResponse
    {
        $term = trim((string) $request->validate(['search' => ['nullable', 'string', 'max:60']])['search'] ?? '');
        $digits = preg_replace('/\D/', '', $term);
        $merchants = $term === '' ? collect() : Merchant::withoutGlobalScopes()->with('company:id,name')
            ->where(fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->when(strlen($digits) >= 6, fn ($q) => $q->orWhere('phone', 'like', '%'.substr($digits, -8).'%')))
            ->orderBy('business_name')->limit(20)->get();

        return response()->json(['data' => $merchants->map(fn (Merchant $m) => $this->merchantLive($m))->values()]);
    }

    /** Offert à un e-commerçant : des commandes en live, du crédit live, ou 30 jours de lives sans limite */
    public function gift(Request $request, int $merchant): JsonResponse
    {
        $model = Merchant::withoutGlobalScopes()->with('company:id,name')->findOrFail($merchant);
        $data = $request->validate([
            'orders' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'credit' => ['nullable', 'integer', 'min:100', 'max:1000000'],
            'month' => ['nullable', 'boolean'],
        ], [], ['orders' => 'commandes offertes', 'credit' => 'crédit offert']);
        if (empty($data['orders']) && empty($data['credit']) && empty($data['month'])) {
            throw ValidationException::withMessages(['orders' => 'Choisissez des commandes, du crédit ou 30 jours à offrir.']);
        }
        // Rien n'est encaissé : pas de frais ni de part d'agence (l'action est notée dans le journal de la console).
        // Sous verrou : un live facturé ou une recharge au même moment n'écrase pas le cadeau (ni l'inverse).
        DB::transaction(function () use ($model, $data) {
            $locked = Merchant::withoutGlobalScopes()->lockForUpdate()->findOrFail($model->id);
            $locked->forceFill([
                'live_orders_bonus' => (int) $locked->live_orders_bonus + (int) ($data['orders'] ?? 0),
                'live_credit' => (int) $locked->live_credit + (int) ($data['credit'] ?? 0),
                ...(! empty($data['month']) ? ['live_plan_until' => ($this->studio->monthlyActive($locked) ? $locked->live_plan_until : now())->copy()->addDays(30)] : []),
            ])->save();
        });

        return response()->json(['data' => $this->merchantLive($model->refresh()->load('company:id,name'))]);
    }

    /** @return array<string, mixed> */
    private function merchantLive(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id,
            'name' => $merchant->business_name,
            'company' => $merchant->company?->name,
            'phone' => $merchant->phone,
            'free_left' => $this->studio->freeLeft($merchant),
            'orders_bonus' => (int) $merchant->live_orders_bonus,
            'credit' => (int) $merchant->live_credit,
            'monthly_until' => $this->studio->monthlyActive($merchant) ? $merchant->live_plan_until : null,
        ];
    }

    /** Versement du crédit d'une agence (fait en dehors de Jibiat, par Wave ou PayDunya) : enregistré ici */
    public function payout(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:120']]);
        // Solde relu sous verrou de l'entreprise : un double envoi ou une facture en cours ne fait pas verser deux fois
        DB::transaction(function () use ($request, $company, $data) {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $balance = PlatformCredit::balance($company->id);
            if ($data['amount'] > $balance) {
                throw ValidationException::withMessages(['amount' => "Le crédit de l'agence n'est que de {$balance} F."]);
            }
            PlatformCredit::create([
                'company_id' => $company->id,
                'amount' => -$data['amount'],
                'reason' => PlatformCredit::PAYOUT,
                'label' => 'Versé par Jibiat'.(filled($data['note'] ?? null) ? ' · '.$data['note'] : ''),
                'created_by' => $request->user()->id,
            ]);
        });

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
