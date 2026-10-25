<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\MerchantAppAccount;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Contract\Contract;

/**
 * API publique, compte du marchand : identité (/me), adresses webhook et reversements. Sert notamment aux
 * applications qui se connectent pour le marchand (Jibiat Marchand) : elles créent elles-mêmes leur adresse
 * webhook et rapprochent les reversements des courses.
 */
class PublicAccountController extends Controller
{
    public const MAX_WEBHOOKS = 10;

    public function me(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $company = $merchant->company;

        return response()->json(['data' => [
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'contact_name' => $merchant->contact_name,
                'phone' => $merchant->phone,
                'status' => $merchant->status->value,
                'default_fee_payer' => $merchant->default_fee_payer?->value,
            ],
            'company' => [
                'name' => $company->name,
                'slug' => $company->slug,
                'url' => $company->url(),
                'phone' => $company->phone,
            ],
            'key' => [
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'scopes' => $key->scopes,
                'expires_at' => $key->expires_at,
            ],
            'webhook_events' => WebhookEvent::values(),
        ]]);
    }

    /**
     * Compte Jibiat Marchand du marchand, parrainé par l'entreprise (contrat merchant-app) : abonnement et part de
     * l'abonnement revenant à l'entreprise. Rapporté par Jibiat Marchand à chaque paiement et une fois par jour.
     */
    public function merchantApp(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'store_url' => ['required', 'url:https,http', 'max:255'],
            'referred' => ['required', 'boolean'],
            'signed_up_at' => ['nullable', 'date'],
            'plan' => ['required', 'array'],
            'plan.name' => ['nullable', 'string', 'max:80'],
            'plan.status' => ['required', Rule::in(Contract::MERCHANT_APP_PLAN_STATUSES)],
            'commission' => ['required', 'array'],
            'commission.rate' => ['required', 'numeric', 'between:0,1'],
            'commission.months' => ['required', 'integer', 'between:0,120'],
            'commission.ends_at' => ['nullable', 'date'],
            'commission.currency' => ['required', 'string', 'size:3'],
            'commission.earned_total' => ['required', 'integer', 'min:0'],
            'commission.earned_month' => ['required', 'integer', 'min:0'],
            'commission.paid_total' => ['required', 'integer', 'min:0'],
        ]);

        $account = MerchantAppAccount::updateOrCreate(['merchant_id' => $merchant->id], [
            'company_id' => $merchant->company_id,
            'store_name' => $data['store_name'],
            'store_url' => $data['store_url'],
            'referred' => $data['referred'],
            'signed_up_at' => $data['signed_up_at'] ?? null,
            'plan_name' => $data['plan']['name'] ?? null,
            'plan_status' => $data['plan']['status'],
            'commission_rate' => $data['commission']['rate'],
            'commission_months' => $data['commission']['months'],
            'commission_ends_at' => $data['commission']['ends_at'] ?? null,
            'currency' => strtoupper($data['commission']['currency']),
            'earned_total' => $data['commission']['earned_total'],
            'earned_month' => $data['commission']['earned_month'],
            'paid_total' => $data['commission']['paid_total'],
            'reported_at' => now(),
        ]);

        return response()->json(['data' => self::presentMerchantApp($account)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentMerchantApp(MerchantAppAccount $account): array
    {
        return [
            'store_name' => $account->store_name,
            'store_url' => $account->store_url,
            'referred' => $account->referred,
            'signed_up_at' => $account->signed_up_at?->toIso8601String(),
            'plan' => ['name' => $account->plan_name, 'status' => $account->plan_status],
            'commission' => [
                'rate' => $account->commission_rate,
                'months' => $account->commission_months,
                'ends_at' => $account->commission_ends_at?->toDateString(),
                'currency' => $account->currency,
                'earned_total' => $account->earned_total,
                'earned_month' => $account->earned_month,
                'paid_total' => $account->paid_total,
            ],
            'reported_at' => $account->reported_at->toIso8601String(),
        ];
    }

    public function webhooks(Request $request): JsonResponse
    {
        return response()->json(['data' => WebhookSubscription::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->latest('id')->get()
            ->map(fn (WebhookSubscription $s) => $this->presentWebhook($s))]);
    }

    /**
     * Le secret de signature n'est renvoyé qu'ici, une seule fois.
     */
    public function storeWebhook(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        UrlGuard::validate($data['url']);

        abort_if(WebhookSubscription::query()->where('merchant_id', $merchant->id)->count() >= self::MAX_WEBHOOKS, 422, 'Dix adresses webhook au plus.');

        $secret = 'whsec_'.Str::random(40);
        $subscription = WebhookSubscription::create([
            ...$data,
            'events' => array_values(array_unique($data['events'])),
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'secret' => $secret,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentWebhook($subscription->refresh()), 'secret' => $secret], 201);
    }

    public function destroyWebhook(Request $request, int $webhook): JsonResponse
    {
        WebhookSubscription::query()->where('merchant_id', $this->merchant($request)->id)->findOrFail($webhook)->delete();

        return response()->json(null, 204);
    }

    public function payouts(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
            // Rattrapage : reversements payés depuis cette date (plutôt que les derniers seulement)
            'paid_since' => ['nullable', 'date'],
        ]);

        $payouts = MerchantPayout::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('paid_since'), fn ($q) => $q->where('paid_at', '>=', $request->date('paid_since')))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json([
            'data' => collect($payouts->items())->map(fn (MerchantPayout $p) => $this->presentPayout($p)),
            'meta' => ['current_page' => $payouts->currentPage(), 'last_page' => $payouts->lastPage(), 'total' => $payouts->total()],
        ]);
    }

    /**
     * Détail d'un reversement : les écritures, avec la course concernée (code de suivi, référence du marchand).
     */
    public function payout(Request $request, string $reference): JsonResponse
    {
        $payout = MerchantPayout::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->where('reference', $reference)
            ->firstOrFail();

        $entries = $payout->entries()->with('order:id,tracking_code,merchant_reference')->orderBy('id')->get();

        return response()->json(['data' => [
            ...$this->presentPayout($payout),
            'entries' => $entries->map(fn (MerchantLedgerEntry $e) => [
                'type' => $e->type->value,
                'amount' => $e->amount,
                'description' => $e->description,
                'order' => $e->order ? ['tracking_code' => $e->order->tracking_code, 'merchant_reference' => $e->order->merchant_reference] : null,
                'created_at' => $e->created_at,
            ]),
        ]]);
    }

    private function merchant(Request $request): Merchant
    {
        return Merchant::findOrFail($request->user()->merchant_id);
    }

    private function presentWebhook(WebhookSubscription $s): array
    {
        return [
            'id' => $s->id,
            'url' => $s->url,
            'events' => $s->events,
            'description' => $s->description,
            'is_active' => $s->is_active,
            'consecutive_failures' => (int) $s->consecutive_failures,
            'created_at' => $s->created_at,
        ];
    }

    private function presentPayout(MerchantPayout $p): array
    {
        return [
            'reference' => $p->reference,
            'status' => $p->status->value,
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'total_collected' => $p->total_collected,
            'total_fees' => $p->total_fees,
            'total_shipping_fees' => $p->total_shipping_fees,
            'total_other_fees' => $p->total_other_fees,
            'total_storage_fees' => $p->total_storage_fees,
            'total_adjustments' => $p->total_adjustments,
            'net_amount' => $p->net_amount,
            'method' => $p->method?->value,
            'transaction_ref' => $p->transaction_ref,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
        ];
    }
}
