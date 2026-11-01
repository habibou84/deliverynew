<?php

namespace App\Services\Premium;

use App\Enums\LedgerEntryType;
use App\Enums\MerchantStatus;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\PremiumCard;
use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Throwable;

/**
 * Cartes Membre Premium Jibiat Marchand : l'entreprise les offre à ses meilleurs marchands, à la main ou toute seule
 * quand le marchand remplit les conditions (carte automatique). Jibiat Marchand crée la carte et applique l'offre
 * (contrat socle 1.6, appels signés avec le secret partagé JIBIAT_PREMIUM_SECRET).
 *
 * Réglages de la console : programme activé, cartes par entreprise (une entreprise peut avoir son propre nombre),
 * qui paie (Jibiat, ou l'entreprise : le prix de chaque carte va sur sa facture du mois), conditions de la carte
 * automatique. « Pas de retard d'argent » : le marchand ne doit pas d'argent à l'entreprise depuis plus de
 * max_debt_days jours (solde non reversé négatif aujourd'hui et déjà à cette date).
 */
class PremiumCards
{
    public const DEFAULTS = [
        'enabled' => true,
        'quota' => 10,
        'funded_by' => 'jibiat',
        'price' => 0,
        'min_delivered' => 50,
        'window_days' => 90,
        'min_success_rate' => 0.85,
        'min_client_days' => 90,
        'max_debt_days' => 30,
        // Niveaux : plafonds de sécurité pour les entreprises
        'max_levels' => 3,
        'max_delivery_discount' => 30,
    ];

    /**
     * Formules Jibiat Marchand qu'une entreprise peut offrir avec un niveau : permise, mois offerts et réduction au
     * plus, prix d'une carte pour l'entreprise (quand c'est elle qui paie).
     */
    public const PLAN_DEFAULTS = [
        'starter' => ['allowed' => true, 'max_months' => 12, 'max_discount' => 50, 'price' => 2000],
        'pro' => ['allowed' => true, 'max_months' => 6, 'max_discount' => 30, 'price' => 6000],
        'business' => ['allowed' => false, 'max_months' => 3, 'max_discount' => 20, 'price' => 15000],
    ];

    /** Ce que contient chaque formule (affiché à l'entreprise et au marchand ; réglé chez Jibiat Marchand) */
    public const PLAN_FEATURES = [
        'starter' => ['name' => 'Starter', 'monthly_price' => 3500, 'features' => ['Commandes et produits sans limite', '3 membres d\'équipe, 5 livreurs', 'Paiement en ligne', '50 actions IA par mois']],
        'pro' => ['name' => 'Pro', 'monthly_price' => 12500, 'features' => ['Tout Starter', '10 membres d\'équipe', 'Domaine personnalisé', 'WhatsApp de la boutique', 'Statistiques avancées', '300 actions IA par mois']],
        'business' => ['name' => 'Business', 'monthly_price' => 35000, 'features' => ['Tout Pro', '3 boutiques', 'API marchand', '1 000 actions IA par mois']],
    ];

    public const FUNDERS = ['jibiat' => 'Jibiat', 'company' => 'L\'entreprise'];

    public const PATH = '/api/partners/v1/premium-cards';

    /** Statuts comptés comme des échecs pour le taux de réussite (comme l'annuaire) */
    private const FAILED = [OrderStatus::Returned, OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Lost];

    public function __construct(private readonly Messenger $messenger) {}

    /**
     * @return array{enabled: bool, quota: int, funded_by: string, price: int, min_delivered: int, window_days: int, min_success_rate: float, min_client_days: int, max_debt_days: int}
     */
    public function settings(): array
    {
        $stored = PlatformSetting::query()->where('key', 'like', 'premium.%')->pluck('value', 'key');
        $get = fn (string $key) => $stored['premium.'.$key] ?? null;
        $d = self::DEFAULTS;

        return [
            'enabled' => $get('enabled') !== null ? $get('enabled') === '1' : $d['enabled'],
            'quota' => (int) ($get('quota') ?? $d['quota']),
            'funded_by' => array_key_exists((string) $get('funded_by'), self::FUNDERS) ? $get('funded_by') : $d['funded_by'],
            'price' => (int) ($get('price') ?? $d['price']),
            'min_delivered' => (int) ($get('min_delivered') ?? $d['min_delivered']),
            'window_days' => (int) ($get('window_days') ?? $d['window_days']),
            'min_success_rate' => (float) ($get('min_success_rate') ?? $d['min_success_rate']),
            'min_client_days' => (int) ($get('min_client_days') ?? $d['min_client_days']),
            'max_debt_days' => (int) ($get('max_debt_days') ?? $d['max_debt_days']),
            'max_levels' => max(1, min(3, (int) ($get('max_levels') ?? $d['max_levels']))),
            'max_delivery_discount' => max(0, min(90, (int) ($get('max_delivery_discount') ?? $d['max_delivery_discount']))),
        ];
    }

    /**
     * Formules permises aux entreprises (console).
     *
     * @return array<string, array{allowed: bool, max_months: int, max_discount: int, price: int}>
     */
    public function plans(): array
    {
        $stored = json_decode((string) PlatformSetting::query()->where('key', 'premium.plans')->value('value'), true);
        $plans = [];
        foreach (self::PLAN_DEFAULTS as $code => $default) {
            $row = is_array($stored[$code] ?? null) ? $stored[$code] : [];
            $plans[$code] = [
                'allowed' => (bool) ($row['allowed'] ?? $default['allowed']),
                'max_months' => max(0, min(36, (int) ($row['max_months'] ?? $default['max_months']))),
                'max_discount' => max(0, min(90, (int) ($row['max_discount'] ?? $default['max_discount']))),
                'price' => max(0, (int) ($row['price'] ?? $default['price'])),
            ];
        }

        return $plans;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     */
    public function updatePlans(array $plans): void
    {
        $current = $this->plans();
        foreach ($plans as $code => $row) {
            if (isset($current[$code])) {
                $current[$code] = array_merge($current[$code], array_intersect_key($row, $current[$code]));
            }
        }
        PlatformSetting::put('premium.plans', json_encode($current));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function updateSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                PlatformSetting::put('premium.'.$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }
    }

    /** Le serveur connaît le secret partagé avec Jibiat Marchand */
    public static function configured(): bool
    {
        return filled(config('services.jibiat_marchand.premium_secret'));
    }

    public function active(): bool
    {
        return $this->settings()['enabled'] && self::configured();
    }

    public function quota(Company $company): int
    {
        return $company->premium_quota ?? $this->settings()['quota'];
    }

    public function used(Company $company): int
    {
        return PremiumCard::forCompany($company->id)->counted()->count();
    }

    /** Prix d'une carte pour l'entreprise : 0 quand Jibiat paie ; selon la formule offerte par un niveau */
    public function price(?string $plan = null): int
    {
        $s = $this->settings();
        if ($s['funded_by'] !== 'company') {
            return 0;
        }

        return $plan !== null && isset($this->plans()[$plan]) ? $this->plans()[$plan]['price'] : max(0, $s['price']);
    }

    /**
     * Activité des marchands de l'entreprise sur la période des conditions, en une requête : courses livrées,
     * échecs (retours, perdus), première course.
     *
     * @param  list<int>|null  $merchantIds
     * @return array<int, array{delivered: int, failed: int, first_order_at: ?string}>
     */
    public function activity(Company $company, ?array $merchantIds = null): array
    {
        $since = now()->subDays($this->settings()['window_days']);
        $failed = array_map(fn (OrderStatus $s) => $s->value, self::FAILED);
        $placeholders = implode(',', array_fill(0, count($failed), '?'));

        $recent = Order::forCompany($company->id)->where('is_test', false)->where('created_at', '>=', $since)
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('merchant_id', $merchantIds))
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS delivered', [OrderStatus::Delivered->value])
            ->selectRaw("SUM(CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END) AS failed", $failed)
            ->get()->keyBy('merchant_id');
        $first = Order::forCompany($company->id)->where('is_test', false)
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('merchant_id', $merchantIds))
            ->groupBy('merchant_id')->selectRaw('merchant_id, MIN(created_at) AS first_at')->pluck('first_at', 'merchant_id');

        $ids = $recent->keys()->merge($first->keys())->unique();

        return $ids->mapWithKeys(fn ($id) => [(int) $id => [
            'delivered' => (int) ($recent[$id]->delivered ?? 0),
            'failed' => (int) ($recent[$id]->failed ?? 0),
            'first_order_at' => $first[$id] ?? null,
        ]])->all();
    }

    /**
     * Marchands qui doivent de l'argent à l'entreprise depuis plus de max_debt_days jours.
     *
     * @return list<int>
     */
    public function lateDebtors(Company $company): array
    {
        $cutoff = now()->subDays($this->settings()['max_debt_days']);
        $unpaid = fn () => MerchantLedgerEntry::forCompany($company->id)->whereNull('payout_id')
            ->where('type', '!=', LedgerEntryType::Payout->value)->groupBy('merchant_id');

        $now = $unpaid()->havingRaw('SUM(amount) < 0')->selectRaw('merchant_id')->pluck('merchant_id');
        if ($now->isEmpty()) {
            return [];
        }
        $then = $unpaid()->whereIn('merchant_id', $now)->where('created_at', '<=', $cutoff)
            ->havingRaw('SUM(amount) < 0')->selectRaw('merchant_id')->pluck('merchant_id');

        return $then->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Classement des marchands actifs de l'entreprise : score sur 100 (60 pour le volume de courses livrées par
     * rapport au seuil, 40 pour le taux de réussite), conditions remplies ou non, carte déjà offerte.
     *
     * @return list<array<string, mixed>>
     */
    public function ranking(Company $company, ?int $limit = null): array
    {
        $s = $this->settings();
        $activity = $this->activity($company);
        $debtors = array_flip($this->lateDebtors($company));
        $cards = PremiumCard::forCompany($company->id)->counted()->pluck('id', 'merchant_id');

        $merchants = Merchant::forCompany($company->id)->where('status', MerchantStatus::Active->value)
            ->get(['id', 'business_name', 'phone', 'whatsapp_phone', 'email', 'created_at']);

        $rows = $merchants->map(function (Merchant $m) use ($activity, $debtors, $cards, $s) {
            $a = $activity[$m->id] ?? ['delivered' => 0, 'failed' => 0, 'first_order_at' => null];

            return $this->evaluate($m, $a, isset($debtors[$m->id]), $s) + ['has_card' => isset($cards[$m->id])];
        })->filter(fn ($r) => $r['delivered'] > 0 || $r['has_card'])
            ->sortBy([['score', 'desc'], ['delivered', 'desc']])->values();

        return ($limit ? $rows->take($limit) : $rows)->all();
    }

    /**
     * @param  array{delivered: int, failed: int, first_order_at: ?string}  $a
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function evaluate(Merchant $m, array $a, bool $lateDebt, array $s): array
    {
        $closed = $a['delivered'] + $a['failed'];
        $rate = $closed > 0 ? round($a['delivered'] / $closed, 3) : null;
        $since = CarbonImmutable::parse($m->created_at);
        if ($a['first_order_at'] !== null && CarbonImmutable::parse($a['first_order_at'])->lt($since)) {
            $since = CarbonImmutable::parse($a['first_order_at']);
        }
        $clientDays = (int) $since->diffInDays(now());
        $score = (int) round(60 * min(1, $a['delivered'] / max(1, $s['min_delivered'])) + 40 * ($rate ?? 0));

        $checks = [
            'delivered' => $a['delivered'] >= $s['min_delivered'],
            'success_rate' => $rate !== null && $rate >= $s['min_success_rate'],
            'client_since' => $clientDays >= $s['min_client_days'],
            'no_late_debt' => ! $lateDebt,
        ];

        return [
            'merchant_id' => $m->id,
            'name' => $m->business_name,
            'delivered' => $a['delivered'],
            'success_rate' => $rate,
            'client_since' => $since->toDateString(),
            'client_days' => $clientDays,
            'late_debt' => $lateDebt,
            'score' => $score,
            'checks' => $checks,
            'eligible' => ! in_array(false, $checks, true),
            'missing_deliveries' => max(0, $s['min_delivered'] - $a['delivered']),
        ];
    }

    /**
     * Où en est un marchand (son application) : conditions et ce qu'il lui manque.
     *
     * @return array<string, mixed>
     */
    public function progress(Merchant $merchant): array
    {
        $company = Company::findOrFail($merchant->company_id);
        $a = $this->activity($company, [$merchant->id])[$merchant->id] ?? ['delivered' => 0, 'failed' => 0, 'first_order_at' => null];
        $late = in_array($merchant->id, $this->lateDebtors($company), true);

        return $this->evaluate($merchant, $a, $late, $this->settings());
    }

    /**
     * Offre la carte au marchand : vérifie le programme, la carte déjà offerte et le nombre de cartes, demande la
     * carte à Jibiat Marchand, la garde, et prévient le marchand avec le lien.
     */
    /**
     * @param  array{plan: string, free_months: int, discount_rate: float}|null  $formula  formule choisie par le
     *                                                                                     niveau (socle 1.8)
     */
    public function offer(Company $company, Merchant $merchant, string $reason, ?User $by = null, ?array $formula = null): PremiumCard
    {
        abort_unless(in_array($reason, Contract::PREMIUM_CARD_REASONS, true), 422);
        if (! $this->settings()['enabled']) {
            throw new BusinessRuleException('Le programme Premium Jibiat Marchand n\'est pas ouvert pour le moment.');
        }
        if (! self::configured()) {
            throw new BusinessRuleException('Le programme Premium n\'est pas encore relié à Jibiat Marchand. Réessayez plus tard.');
        }
        if ($merchant->company_id !== $company->id || $merchant->status !== MerchantStatus::Active) {
            throw new BusinessRuleException('Ce marchand n\'est pas un client actif de l\'entreprise.', 'merchant_id');
        }
        if (PremiumCard::forCompany($company->id)->counted()->where('merchant_id', $merchant->id)->exists()) {
            throw new BusinessRuleException('Ce marchand a déjà sa carte Membre Premium.', 'merchant_id');
        }
        if ($this->used($company) >= $this->quota($company)) {
            throw new BusinessRuleException('Toutes vos cartes Premium sont offertes ('.$this->quota($company).'). Demandez-en d\'autres à Jibiat.');
        }

        $progress = $this->progress($merchant);
        $body = [
            'company' => ['slug' => $company->slug, 'name' => $company->name, 'phone' => PhoneNumber::normalize($company->phone)],
            'merchant' => [
                'reference' => (string) $merchant->id,
                'name' => $merchant->business_name,
                'phone' => PhoneNumber::normalize($merchant->messagingPhone()) ?? (string) $merchant->messagingPhone(),
                'email' => $merchant->email ?: null,
            ],
            'reason' => $reason,
            'stats' => [
                'delivered_90d' => $progress['delivered'],
                'success_rate' => $progress['success_rate'],
                'client_since' => $progress['client_since'],
            ],
            ...($formula !== null ? ['offer' => $formula] : []),
        ];

        $response = $this->send('POST', self::PATH, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $data = $response?->successful() ? $response->json('data') : null;
        if (! is_array($data) || Contract::validate('premium-card', ['data' => $data]) !== []) {
            Log::warning('Carte Premium : réponse refusée de Jibiat Marchand', ['company' => $company->id, 'merchant' => $merchant->id, 'status' => $response?->status()]);

            throw new BusinessRuleException('Jibiat Marchand n\'a pas pu créer la carte. Réessayez dans quelques minutes.');
        }

        $card = DB::transaction(fn () => PremiumCard::create([
            'company_id' => $company->id,
            'merchant_id' => $merchant->id,
            ...$this->fields($data),
            'reason' => $reason,
            'offered_by' => $by?->id,
            'offered_at' => now(),
            'price' => $this->price($formula['plan'] ?? null),
        ]));

        $this->announce($company, $merchant, $card, (int) $data['free_months']);

        return $card;
    }

    /**
     * Annule une carte (chez Jibiat Marchand puis ici). Une carte annulée ne compte plus : l'entreprise peut en offrir
     * une autre.
     */
    public function revoke(PremiumCard $card): PremiumCard
    {
        $company = Company::findOrFail($card->company_id);
        $response = $this->send('DELETE', self::PATH.'/'.rawurlencode($card->code).'?company='.rawurlencode($company->slug));
        // Carte déjà inconnue de Jibiat Marchand : on l'annule quand même ici
        if ($response === null || ($response->failed() && $response->status() !== 404)) {
            throw new BusinessRuleException('Jibiat Marchand n\'a pas pu annuler la carte. Réessayez dans quelques minutes.');
        }
        $data = $response->successful() ? $response->json('data') : null;
        $card->fill(is_array($data) ? $this->fields($data) : [])->forceFill(['state' => 'revoked', 'revoked_at' => $card->revoked_at ?? now()])->save();

        return $card;
    }

    /**
     * Relit les cartes de l'entreprise chez Jibiat Marchand (état, boutique, fin des mois offerts).
     */
    public function sync(Company $company): int
    {
        if (! self::configured() || ! PremiumCard::forCompany($company->id)->exists()) {
            return 0;
        }
        $response = $this->send('GET', self::PATH.'?company='.rawurlencode($company->slug));
        $list = $response?->successful() ? $response->json() : null;
        if (! is_array($list) || Contract::validate('premium-card-list', $list) !== []) {
            return 0;
        }

        $updated = 0;
        foreach ($list['data'] as $data) {
            $card = PremiumCard::forCompany($company->id)->where('code', $data['code'])->first();
            if ($card !== null) {
                $card->fill($this->fields($data))->forceFill(['synced_at' => now()]);
                if ($data['state'] === 'revoked' && $card->revoked_at === null) {
                    $card->revoked_at = now();
                }
                $card->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Carte automatique (tâche du jour) : pour chaque entreprise qui l'a activée, offre la carte aux marchands qui
     * remplissent les conditions et n'en ont pas, les meilleurs d'abord, tant qu'il reste des cartes.
     *
     * @return array{companies: int, offered: int, synced: int}
     */
    public function run(): array
    {
        $counts = ['companies' => 0, 'offered' => 0, 'synced' => 0];
        if (! self::configured()) {
            return $counts;
        }

        Company::query()->orderBy('id')->each(function (Company $company) use (&$counts) {
            $counts['synced'] += $this->sync($company);
            if (! $this->settings()['enabled'] || ! $company->premium_auto) {
                return;
            }
            $counts['companies']++;
            $left = $this->quota($company) - $this->used($company);
            foreach ($this->ranking($company) as $row) {
                if ($left <= 0) {
                    break;
                }
                if (! $row['eligible'] || $row['has_card']) {
                    continue;
                }
                try {
                    $this->offer($company, Merchant::forCompany($company->id)->findOrFail($row['merchant_id']), 'automatic');
                    $counts['offered']++;
                    $left--;
                } catch (Throwable $e) {
                    Log::warning('Carte Premium automatique non offerte', ['company' => $company->id, 'merchant' => $row['merchant_id'], 'error' => $e->getMessage()]);
                }
            }
        });

        return $counts;
    }

    /**
     * Cartes payées par l'entreprise et pas encore facturées, offertes avant la fin de la période (facture du mois).
     *
     * @return array{count: int, amount: int, ids: list<int>}
     */
    public function billable(Company $company, CarbonImmutable $periodEnd): array
    {
        $cards = PremiumCard::forCompany($company->id)->whereNull('platform_invoice_id')->where('price', '>', 0)
            ->where('offered_at', '<', $periodEnd)->get(['id', 'price']);

        return ['count' => $cards->count(), 'amount' => (int) $cards->sum('price'), 'ids' => $cards->modelKeys()];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PremiumCard $card): array
    {
        return [
            'id' => $card->id,
            'merchant_id' => $card->merchant_id,
            'merchant' => $card->merchant?->business_name,
            'code' => $card->code,
            'label' => $card->label,
            'url' => $card->url,
            'state' => $card->state,
            'state_label' => PremiumCard::STATE_LABELS[$card->state] ?? $card->state,
            'reason' => $card->reason,
            'offered_at' => $card->offered_at?->toIso8601String(),
            'offered_by' => $card->offeredBy?->name,
            'free_until' => $card->free_until?->toDateString(),
            'activated_at' => $card->activated_at?->toIso8601String(),
            'store_name' => $card->store_name,
            'store_url' => $card->store_url,
            'price' => $card->price,
        ];
    }

    /**
     * Champs gardés de la carte renvoyée par Jibiat Marchand (contrat premium-card).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return [
            'code' => $data['code'],
            'number' => $data['number'],
            'label' => mb_substr((string) $data['label'], 0, 120),
            'url' => $data['url'],
            'state' => in_array($data['state'], Contract::PREMIUM_CARD_STATES, true) ? $data['state'] : 'available',
            'free_until' => $data['free_until'] ?? null,
            'activated_at' => isset($data['activated_at']) ? CarbonImmutable::parse($data['activated_at']) : null,
            'store_name' => $data['store']['name'] ?? null,
            'store_url' => $data['store']['url'] ?? null,
            'plan' => isset($data['plan']) ? mb_substr((string) $data['plan'], 0, 20) : null,
            'free_months' => isset($data['free_months']) ? (int) $data['free_months'] : null,
            'discount_rate' => isset($data['discount_rate']) ? (float) $data['discount_rate'] : null,
        ];
    }

    private function announce(Company $company, Merchant $merchant, PremiumCard $card, int $months): void
    {
        DB::afterCommit(fn () => $this->messenger->merchantMessage($merchant, 'premium.offered', WhatsAppTemplate::PremiumCardOffered, [
            $merchant->business_name,
            $company->name,
            $months,
            $card->url,
        ]));
    }

    /**
     * Appel signé à Jibiat Marchand : contenu signé = corps brut (POST), chemin avec sa requête (GET, DELETE).
     */
    private function send(string $method, string $target, string $body = ''): ?Response
    {
        $timestamp = time();
        $signed = $method === 'POST' ? $body : $target;
        $request = Http::baseUrl((string) config('services.jibiat_marchand.api_url'))
            ->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
            ->withHeaders([
                Contract::PARTNER_TIMESTAMP_HEADER => (string) $timestamp,
                Contract::PARTNER_SIGNATURE_HEADER => WebhookSignature::sign((string) config('services.jibiat_marchand.premium_secret'), $timestamp, $signed),
            ]);

        try {
            return match ($method) {
                'POST' => $request->withBody($body, 'application/json')->post($target),
                'DELETE' => $request->delete($target),
                default => $request->get($target),
            };
        } catch (Throwable $e) {
            Log::warning('Carte Premium : Jibiat Marchand injoignable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
