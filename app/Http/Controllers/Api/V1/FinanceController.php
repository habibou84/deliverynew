<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\CourierRemittance;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\User;
use App\Services\Finance\CashDesk;
use App\Services\Finance\CourierPayroll;
use App\Services\Finance\MerchantPayouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Caisse de l'entreprise de livraison : versements des livreurs, grand livre et
 * reversements des marchands, paie des livreurs. Les marchands consultent leur
 * propre grand livre et leurs reversements.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly CashDesk $cashDesk,
        private readonly MerchantPayouts $payouts,
        private readonly CourierPayroll $payroll,
    ) {}

    // ───────────── Argent chez les livreurs et versements ─────────────

    public function cash(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        // Agrégats groupés par livreur (pas de requête par ligne)
        $cash = CashCollection::inCourierHands()->groupBy('courier_id')
            ->selectRaw('courier_id, SUM('.CashCollection::amountDueSql().') AS total, COUNT(*) AS n, MIN(collected_at) AS oldest')
            ->get()->keyBy('courier_id');
        $unpaid = CourierEarning::whereNull('payout_id')->groupBy('courier_id')
            ->selectRaw('courier_id, SUM(amount) AS total')->pluck('total', 'courier_id');

        $couriers = Courier::with('user')->get()->map(fn (Courier $c) => [
            'courier_id' => $c->id,
            'name' => $c->user?->name,
            'phone' => $c->user?->phone,
            'cash_in_hand' => (int) ($cash[$c->id]->total ?? 0),
            'unpaid' => (int) ($unpaid[$c->id] ?? 0),
            'pending_collections' => (int) ($cash[$c->id]->n ?? 0),
            'oldest_collected_at' => $cash[$c->id]->oldest ?? null,
        ])
            // Montant absolu : un livreur à qui la caisse doit des frais avancés reste en tête de liste
            ->sortByDesc(fn ($c) => abs($c['cash_in_hand']))->values();

        return response()->json([
            'data' => $couriers,
            'totals' => [
                'cash_in_hands' => $couriers->sum('cash_in_hand'),
                'collected_today' => (int) CashCollection::whereDate('collected_at', today())->sum('amount_collected'),
                'received_today' => (int) CourierRemittance::whereDate('received_at', today())->sum('amount_received'),
            ],
        ]);
    }

    public function collections(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request);

        $collections = $courier->collections()
            ->when($request->boolean('pending', true), fn ($q) => $q->inCourierHands())
            ->with('order:id,tracking_code,recipient_name,merchant_id')
            ->orderBy('collected_at')
            ->get()
            ->map(fn (CashCollection $c) => $this->collectionData($c));

        return response()->json(['data' => $collections]);
    }

    public function storeRemittance(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            // Négatif : la caisse rembourse au livreur des frais qu'il a avancés
            'amount_received' => ['required', 'integer', 'min:-10000000', 'max:100000000'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['amount_received' => 'montant reçu', 'courier_id' => 'livreur']);

        $remittance = $this->cashDesk->remit(
            $request->user(),
            Courier::findOrFail($data['courier_id']),
            $data['amount_received'],
            $data['collection_ids'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['data' => $this->remittanceData($remittance->load(['courier.user', 'receiver']))], 201);
    }

    public function remittances(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $request->validate(['courier_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $remittances = CourierRemittance::with(['courier.user', 'receiver'])
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date('to')))
            ->latest('received_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $remittances->map(fn ($r) => $this->remittanceData($r))]);
    }

    // ───────────── Marchands : grand livre et reversements ─────────────

    public function merchants(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $summaries = $this->payouts->summaries();
        $empty = ['available' => 0, 'pending_cash' => 0, 'unpaid_total' => 0];

        $merchants = Merchant::orderBy('business_name')->get()
            ->map(fn (Merchant $m) => ['merchant_id' => $m->id, 'business_name' => $m->business_name, ...($summaries[$m->id] ?? $empty)])
            ->filter(fn ($row) => $row['unpaid_total'] !== 0 || $request->boolean('all'))
            ->values();

        return response()->json(['data' => $merchants]);
    }

    public function ledger(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeMerchantAccess($request, $merchant);

        $request->validate(['unpaid' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'max:200']]);

        $entries = $merchant->ledgerEntries()
            ->with(['order:id,tracking_code,recipient_name,status', 'payout:id,reference,status'])
            ->when($request->boolean('unpaid'), fn ($q) => $q->whereNull('payout_id'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($entries->items())->map(fn ($e) => $this->entryData($e)),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
            'summary' => $this->payouts->summary($merchant),
        ]);
    }

    public function adjustMerchant(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'description' => ['required', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'description' => 'motif']);

        $entry = $this->payouts->adjust($request->user(), $merchant, $data['amount'], $data['description']);

        return response()->json(['data' => $this->entryData($entry)], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can(Permission::FinanceView->value), 403);

        $request->validate(['merchant_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string']]);

        $payouts = MerchantPayout::with('merchant')
            ->when($user->merchant_id !== null, fn ($q) => $q->where('merchant_id', $user->merchant_id))
            ->when($user->merchant_id === null && $request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $payouts->map(fn ($p) => $this->payoutData($p))]);
    }

    public function storePayout(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->where('company_id', $request->user()->company_id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = $this->payouts->create($request->user(), Merchant::findOrFail($data['merchant_id']), $data['notes'] ?? null);

        return response()->json(['data' => $this->payoutData($payout->load('merchant'), withEntries: true)], 201);
    }

    public function showPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeMerchantAccess($request, $payout->merchant);

        return response()->json(['data' => $this->payoutData($payout->load('merchant'), withEntries: true)]);
    }

    public function payPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $this->validatePayment($request);

        $payout = $this->payouts->markPaid($request->user(), $payout, PaymentMethod::from($data['method']), $data['transaction_ref'] ?? null);

        return response()->json(['data' => $this->payoutData($payout->load('merchant'), withEntries: true)]);
    }

    public function cancelPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        return response()->json(['data' => $this->payoutData($this->payouts->cancel($payout)->load('merchant'))]);
    }

    // ───────────── Paie des livreurs ─────────────

    public function earnings(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'data' => $courier->earnings()->with('order:id,tracking_code')->whereNull('payout_id')->latest('id')->get()
                ->map(fn ($e) => $this->earningData($e)),
            'summary' => $this->payroll->summary($courier),
        ]);
    }

    public function adjustCourier(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'description' => ['required', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'description' => 'motif']);

        return response()->json([
            'data' => $this->earningData($this->payroll->adjust($request->user(), $courier, $data['amount'], $data['description'])),
        ], 201);
    }

    public function courierPayouts(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $payouts = CourierPayout::with('courier.user')
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $payouts->map(fn ($p) => $this->courierPayoutData($p))]);
    }

    public function storeCourierPayout(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ]);

        $payout = $this->payroll->create($request->user(), Courier::findOrFail($data['courier_id']));

        return response()->json(['data' => $this->courierPayoutData($payout->load('courier.user'), withEarnings: true)], 201);
    }

    public function showCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json(['data' => $this->courierPayoutData($courierPayout->load('courier.user'), withEarnings: true)]);
    }

    public function payCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $this->validatePayment($request);

        $payout = $this->payroll->markPaid($request->user(), $courierPayout, PaymentMethod::from($data['method']), $data['transaction_ref'] ?? null);

        return response()->json(['data' => $this->courierPayoutData($payout->load('courier.user'), withEarnings: true)]);
    }

    public function cancelCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        return response()->json(['data' => $this->courierPayoutData($this->payroll->cancel($courierPayout)->load('courier.user'))]);
    }

    // ───────────── Livreur connecté ─────────────

    public function wallet(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        return response()->json(['data' => [
            ...$this->payroll->summary($courier),
            'earned_today' => (int) $courier->earnings()->whereIn('type', ['pickup', 'delivery', 'return'])->whereDate('created_at', today())->sum('amount'),
            'collections' => $courier->collections()->inCourierHands()->with('order:id,tracking_code,recipient_name,merchant_id')
                ->orderBy('collected_at')->get()->map(fn ($c) => $this->collectionData($c)),
            'recent_payouts' => $courier->payouts()->where('status', 'paid')->latest('paid_at')->limit(5)->get()
                ->map(fn ($p) => ['reference' => $p->reference, 'amount' => $p->amount, 'paid_at' => $p->paid_at]),
        ]]);
    }

    // ───────────── Outils ─────────────

    private function authorizeStaff(Request $request, bool $manage = false): void
    {
        $user = $request->user();
        $permission = $manage ? Permission::FinanceManage : Permission::FinanceView;

        abort_unless($user->merchant_id === null && $user->can($permission->value), 403);
    }

    private function authorizeMerchantAccess(Request $request, Merchant $merchant): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->can(Permission::FinanceView->value) && ($user->merchant_id === null || $user->merchant_id === $merchant->id),
            403,
        );
    }

    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ], [], ['method' => 'mode de paiement', 'transaction_ref' => 'référence']);
    }

    private function collectionData(CashCollection $c): array
    {
        return [
            'id' => $c->id,
            'order_id' => $c->order_id,
            'tracking_code' => $c->order?->tracking_code,
            'recipient_name' => $c->order?->recipient_name,
            'amount_expected' => $c->amount_expected,
            'amount_collected' => $c->amount_collected,
            'courier_expense' => $c->courier_expense,
            'amount_due' => $c->amountDue(),
            'method' => $c->method,
            'method_label' => $c->method->label(),
            'received_by_company' => $c->received_by_company,
            'collected_at' => $c->collected_at,
            'remittance_id' => $c->remittance_id,
        ];
    }

    private function remittanceData(CourierRemittance $r): array
    {
        return [
            'id' => $r->id,
            'courier_id' => $r->courier_id,
            'courier_name' => $r->courier?->user?->name,
            'amount_expected' => $r->amount_expected,
            'amount_received' => $r->amount_received,
            'difference' => $r->difference,
            'received_by' => $r->receiver?->name,
            'received_at' => $r->received_at,
            'notes' => $r->notes,
        ];
    }

    private function entryData(MerchantLedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => $e->type->label(),
            'amount' => $e->amount,
            'description' => $e->description,
            'order' => $e->order ? ['id' => $e->order->id, 'tracking_code' => $e->order->tracking_code, 'recipient_name' => $e->order->recipient_name] : null,
            'payout' => $e->payout ? ['id' => $e->payout->id, 'reference' => $e->payout->reference, 'status' => $e->payout->status] : null,
            'created_at' => $e->created_at,
        ];
    }

    private function payoutData(MerchantPayout $p, bool $withEntries = false): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'merchant' => ['id' => $p->merchant->id, 'business_name' => $p->merchant->business_name, 'phone' => $p->merchant->phone],
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'total_collected' => $p->total_collected,
            'total_fees' => $p->total_fees,
            'total_shipping_fees' => $p->total_shipping_fees,
            'total_adjustments' => $p->total_adjustments,
            'net_amount' => $p->net_amount,
            'status' => $p->status,
            'status_label' => $p->status->label(),
            'method' => $p->method,
            'method_label' => $p->method?->label(),
            'transaction_ref' => $p->transaction_ref,
            'notes' => $p->notes,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
        ] + ($withEntries ? ['entries' => $p->entries()
            ->with(['order:id,tracking_code,recipient_name,status', 'payout:id,reference,status'])
            ->where('type', '!=', 'payout')->orderBy('id')->get()->map(fn ($e) => $this->entryData($e))] : []);
    }

    private function earningData(CourierEarning $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => $e->type->label(),
            'amount' => $e->amount,
            'description' => $e->description,
            'tracking_code' => $e->order?->tracking_code,
            'created_at' => $e->created_at,
        ];
    }

    private function courierPayoutData(CourierPayout $p, bool $withEarnings = false): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'courier_id' => $p->courier_id,
            'courier_name' => $p->courier?->user?->name,
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'amount' => $p->amount,
            'status' => $p->status,
            'status_label' => $p->status->label(),
            'method_label' => $p->method?->label(),
            'transaction_ref' => $p->transaction_ref,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
        ] + ($withEarnings ? ['earnings' => $p->earnings()->with('order:id,tracking_code')->orderBy('id')->get()
            ->map(fn ($e) => $this->earningData($e))] : []);
    }
}
