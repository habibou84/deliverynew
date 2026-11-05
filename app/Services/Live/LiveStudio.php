<?php

namespace App\Services\Live;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Live;
use App\Models\LiveAiCard;
use App\Models\LiveCharge;
use App\Models\LiveItem;
use App\Models\LiveOrder;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Stock\StockKeeper;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outil live des e-commerçants : préparation (produits, codes A1, A2…), fiche produit par l'IA (seul usage de l'IA,
 * compté), démarrage payant, commandes prises pendant le direct (courses créées chez l'agence), conseils calculés,
 * bilan. Les frais sont débités à l'e-commerçant (ou offerts par l'agence) ; la part de Jibiat va sur la facture
 * de la plateforme.
 */
class LiveStudio
{
    public const DEFAULTS = [
        'enabled' => true,
        'price_live' => 1000,
        'cards_per_live' => 10,
        'price_month' => 5000,
        'cards_per_month' => 60,
        'pack_price' => 500,
        'pack_cards' => 20,
        'trial_lives' => 1,
        'trial_cards' => 5,
        'agency_share' => 0,
        'retakes' => 3,
        'cards_per_day' => 50,
        'items_per_card' => 9,
        'low_stock' => 3,
        'idle_minutes' => 15,
        'risk_refusals' => 3,
        'min_sales_range' => 20,
        'monthly_ai_cap' => 200000,
    ];

    /** Statuts d'une course refusée ou revenue : clientes à risque */
    private const REFUSED = [OrderStatus::Returned, OrderStatus::Returning, OrderStatus::ReturnAssigned, OrderStatus::Cancelled];

    public function __construct(
        private readonly OrderService $orders,
        private readonly StockKeeper $stock,
        private readonly ProductRecognizer $recognizer,
    ) {}

    /** @return array<string, int|bool> */
    public function settings(): array
    {
        $stored = json_decode((string) PlatformSetting::get('live.settings'), true) ?: [];

        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    /** @param array<string, mixed> $values */
    public function updateSettings(array $values): array
    {
        $settings = [...$this->settings(), ...array_intersect_key($values, self::DEFAULTS)];
        PlatformSetting::put('live.settings', json_encode($settings));

        return $settings;
    }

    // ── Offre de l'e-commerçante ───────────────────────────────────────────────────────────────────

    public function monthlyActive(Merchant $merchant): bool
    {
        return $merchant->live_plan_until !== null && $merchant->live_plan_until->isFuture();
    }

    public function trialLeft(Merchant $merchant): int
    {
        $used = Live::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('plan', 'trial')->count();

        return max(0, (int) $this->settings()['trial_lives'] - $used);
    }

    /** Fiches IA du forfait utilisées depuis le début du forfait en cours */
    public function monthlyCardsUsed(Merchant $merchant): int
    {
        if (! $this->monthlyActive($merchant)) {
            return 0;
        }

        return LiveAiCard::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('source', 'monthly')
            ->where('created_at', '>=', $merchant->live_plan_until->copy()->subDays(30))->count();
    }

    /** @return array<string, mixed> */
    public function offer(Merchant $merchant): array
    {
        $s = $this->settings();
        $company = $merchant->company;

        return [
            'enabled' => (bool) $s['enabled'],
            'offered_by_company' => (bool) $company?->live_offered,
            'trial_left' => $this->trialLeft($merchant),
            'monthly_until' => $this->monthlyActive($merchant) ? $merchant->live_plan_until : null,
            'monthly_cards_left' => $this->monthlyActive($merchant) ? max(0, $s['cards_per_month'] - $this->monthlyCardsUsed($merchant)) : 0,
            'extra_cards' => (int) $merchant->live_extra_cards,
            'prices' => [
                'live' => $s['price_live'], 'cards_per_live' => $s['cards_per_live'],
                'month' => $s['price_month'], 'cards_per_month' => $s['cards_per_month'],
                'pack' => $s['pack_price'], 'pack_cards' => $s['pack_cards'],
                'trial_cards' => $s['trial_cards'],
            ],
            'items_per_card' => $s['items_per_card'],
            // Numéro de la carte : le WhatsApp relié (commandes automatiques), sinon celui de la boutique
            'phone' => $merchant->live_wa_status === 'connected' && $merchant->live_wa_phone ? $merchant->live_wa_phone : $merchant->messagingPhone(),
        ];
    }

    // ── Préparation ───────────────────────────────────────────────────────────────────────────────

    /** Live en préparation (un seul à la fois), créé au besoin */
    public function draft(Merchant $merchant, User $user): Live
    {
        $this->ensureEnabled();

        return Live::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->whereIn('status', [Live::DRAFT, Live::LIVE])->latest('id')->first()
            ?? Live::create([
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'name' => 'Mon live du '.now($merchant->company?->timezone ?: config('app.timezone'))->translatedFormat('d F'),
                'status' => Live::DRAFT,
                'created_by' => $user->id,
            ]);
    }

    /**
     * Produits du live, dans l'ordre : codes A1…A9, puis B1… (une carte par lettre).
     *
     * Un même produit peut revenir plusieurs fois avec une variante différente (taille, couleur) : chaque
     * variante a son code.
     *
     * @param  list<array{product_id: int, variant?: ?string, price?: ?int, quantity?: ?int}>  $rows
     */
    public function setItems(Live $live, array $rows, ?string $name = null): Live
    {
        abort_if($live->status === Live::ENDED, 422, 'Ce live est terminé.');
        $perCard = (int) $this->settings()['items_per_card'];
        $products = Product::forCompany($live->company_id)->where('merchant_id', $live->merchant_id)->with('levels')
            ->findMany(array_column($rows, 'product_id'))->keyBy('id');

        DB::transaction(function () use ($live, $rows, $products, $perCard, $name) {
            $key = fn ($productId, $variant) => $productId.'|'.mb_strtolower(trim((string) $variant));
            $existing = $live->items()->get()->keyBy(fn (LiveItem $i) => $key($i->product_id, $i->variant));
            $keep = [];
            $seen = [];
            $position = 0;
            // Produits retirés mais déjà commandés : ils gardent leur code, les autres ne le prennent pas
            $wanted = collect($rows)->map(fn ($r) => $key($r['product_id'], $r['variant'] ?? null))->flip();
            $taken = $existing->reject(fn ($i, $k) => $wanted->has($k))->filter(fn (LiveItem $i) => $i->orders()->exists())->pluck('code')->all();
            $slot = 0;
            foreach ($rows as $row) {
                $product = $products->get($row['product_id']) ?? throw new BusinessRuleException('Produit introuvable.', 'items');
                $variant = trim((string) ($row['variant'] ?? '')) ?: null;
                $k = $key($product->id, $variant);
                if (isset($seen[$k])) {
                    throw new BusinessRuleException("{$product->name} : deux fois la même variante.", 'items');
                }
                $seen[$k] = true;
                do {
                    $code = chr(65 + intdiv($slot, $perCard)).(($slot % $perCard) + 1);
                    $slot++;
                } while (in_array($code, $taken, true));
                $item = $existing->get($k) ?? new LiveItem(['live_id' => $live->id, 'product_id' => $product->id, 'sold' => 0]);
                $stock = $product->levels->isNotEmpty() ? (int) $product->levels->sum(fn ($l) => $l->available()) : null;
                $item->fill([
                    'code' => 'T'.$position, // code provisoire : évite les doublons pendant la renumérotation
                    'name' => $product->name,
                    'variant' => $variant ? mb_substr($variant, 0, 40) : null,
                    'price' => max(0, (int) ($row['price'] ?? $item->price ?? $product->price ?? 0)),
                    'quantity' => max(1, (int) ($row['quantity'] ?? $item->quantity ?? $stock ?? 1)),
                    'position' => $position,
                ])->save();
                $keep[$item->id] = $code;
                $position++;
            }
            // Les produits retirés du live disparaissent s'ils n'ont pas de commande
            $live->items()->whereNotIn('id', array_keys($keep))->whereDoesntHave('orders')->delete();
            foreach ($keep as $id => $code) {
                LiveItem::query()->whereKey($id)->update(['code' => $code]);
            }
            if ($name !== null && trim($name) !== '') {
                $live->forceFill(['name' => mb_substr(trim($name), 0, 120)])->save();
            }
        });

        return $live->refresh()->load('items.product');
    }

    /**
     * Fiche produit par l'IA à partir d'une photo. Une fiche = un produit ; reprendre la photo avec le même jeton
     * ne compte pas en plus jusqu'au nombre de reprises gratuites.
     *
     * @return array<string, mixed>
     */
    public function recognize(Live $live, Merchant $merchant, UploadedFile $photo, ?string $token): array
    {
        $s = $this->settings();
        $card = $token ? LiveAiCard::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('token', $token)->first() : null;

        if ($card !== null) {
            if ($card->attempts > (int) $s['retakes']) {
                throw new BusinessRuleException('Vous avez déjà repris cette photo plusieurs fois : remplissez la fiche vous-même ou commencez un nouveau produit.');
            }
        } else {
            $source = $this->cardSource($live, $merchant);
            $today = LiveAiCard::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('created_at', '>=', now()->startOfDay())->count();
            if ($today >= (int) $s['cards_per_day']) {
                throw new BusinessRuleException('Limite de fiches de la journée atteinte. Réessayez demain.');
            }
            if ($this->platformCostThisMonth() >= (int) $s['monthly_ai_cap'] * 1000) {
                throw new BusinessRuleException('L\'assistant est momentanément indisponible. Remplissez la fiche vous-même.');
            }
            $card = new LiveAiCard([
                'company_id' => $merchant->company_id, 'merchant_id' => $merchant->id, 'live_id' => $live->id,
                'token' => Str::random(32), 'source' => $source, 'attempts' => 0,
            ]);
        }

        $result = $this->recognizer->recognize($photo);

        DB::transaction(function () use ($card, $result, $live, $merchant) {
            $isNew = ! $card->exists;
            $card->attempts++;
            $card->cost_millis += $result['cost_millis'];
            $card->save();
            if ($isNew) {
                if ($card->source === 'pack') {
                    Merchant::query()->whereKey($merchant->id)->where('live_extra_cards', '>', 0)->decrement('live_extra_cards');
                }
                $live->increment('ai_cards_used');
            }
        });

        return [
            'token' => $card->token,
            'name' => $result['name'],
            'description' => $result['description'],
            'category' => $result['category'],
            'price_range' => $this->priceRange($result['category']),
            'cards' => $this->cardsLeft($live->refresh(), $merchant->refresh()),
        ];
    }

    /** D'où vient la prochaine fiche IA : comprise dans le live, le forfait, ou un pack acheté */
    private function cardSource(Live $live, Merchant $merchant): string
    {
        $s = $this->settings();
        $includedInLive = match (true) {
            $this->monthlyActive($merchant) => 0,
            $this->trialLeft($merchant) > 0 && $live->status === Live::DRAFT, $live->plan === 'trial' => (int) $s['trial_cards'],
            default => (int) $s['cards_per_live'],
        };
        $usedInLive = LiveAiCard::forCompany($live->company_id)->where('live_id', $live->id)->where('source', 'live')->count();

        return match (true) {
            $this->monthlyActive($merchant) && $this->monthlyCardsUsed($merchant) < (int) $s['cards_per_month'] => 'monthly',
            $usedInLive < $includedInLive => 'live',
            $merchant->live_extra_cards > 0 => 'pack',
            default => throw new BusinessRuleException("Plus de fiches IA pour ce live. Achetez {$s['pack_cards']} fiches pour {$s['pack_price']} F, ou remplissez la fiche vous-même.", 'cards'),
        };
    }

    /** @return array{used: int, left: int} */
    public function cardsLeft(Live $live, Merchant $merchant): array
    {
        $s = $this->settings();
        $used = (int) $live->ai_cards_used;
        if ($this->monthlyActive($merchant)) {
            return ['used' => $used, 'left' => max(0, $s['cards_per_month'] - $this->monthlyCardsUsed($merchant)) + (int) $merchant->live_extra_cards];
        }
        $included = ($live->plan === 'trial' || ($live->status === Live::DRAFT && $this->trialLeft($merchant) > 0)) ? $s['trial_cards'] : $s['cards_per_live'];
        $usedInLive = LiveAiCard::forCompany($live->company_id)->where('live_id', $live->id)->where('source', 'live')->count();

        return ['used' => $used, 'left' => max(0, $included - $usedInLive) + (int) $merchant->live_extra_cards];
    }

    /**
     * Fourchette de prix vendus pour cette catégorie dans les boutiques Jibiat (toutes entreprises, anonyme), seulement
     * avec assez de ventes ; sinon null.
     *
     * @return array{min: int, max: int, sales: int}|null
     */
    public function priceRange(?string $category): ?array
    {
        if ($category === null || $category === '') {
            return null;
        }
        $prices = OrderItem::query()
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.category', $category)
            ->where('order_items.unit_price', '>', 0)
            ->latest('order_items.id')->limit(500)
            ->pluck('order_items.unit_price')->map(fn ($p) => (int) $p)->sort()->values();
        if ($prices->count() < (int) $this->settings()['min_sales_range']) {
            return null;
        }
        $at = fn (float $q) => $prices[(int) floor($q * ($prices->count() - 1))];

        return ['min' => (int) (round($at(0.25) / 500) * 500), 'max' => (int) (round($at(0.75) / 500) * 500), 'sales' => $prices->count()];
    }

    /** Produit enregistré dans le catalogue (fiche IA ou à la main) avec sa photo et son stock */
    public function createProduct(Merchant $merchant, User $user, array $data, ?UploadedFile $photo): Product
    {
        return DB::transaction(function () use ($merchant, $user, $data, $photo) {
            $product = Product::create([
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'price' => (int) $data['price'],
                'is_active' => true,
                'shop_visible' => (bool) ($data['keep_in_catalog'] ?? true),
            ]);
            if ($photo) {
                $product->forceFill(['photo_path' => $photo->store("products/merchant-{$merchant->id}", 'local')])->save();
            }
            if (($quantity = (int) ($data['quantity'] ?? 0)) > 0) {
                $this->stock->receive($user, $product, $this->stock->location($merchant), $quantity, 'Ajouté pour un live');
            }

            return $product;
        });
    }

    // ── Démarrage, frais ──────────────────────────────────────────────────────────────────────────

    public function start(Live $live, User $user): Live
    {
        $this->ensureEnabled();
        if ($live->status !== Live::DRAFT) {
            return $live;
        }
        if ($live->items()->count() === 0) {
            throw new BusinessRuleException('Choisissez au moins un produit pour votre live.', 'items');
        }
        $merchant = $live->merchant()->with('company')->firstOrFail();
        $s = $this->settings();

        return DB::transaction(function () use ($live, $merchant, $user, $s) {
            [$plan, $included] = match (true) {
                (bool) $merchant->company?->live_offered => ['offered', (int) $s['cards_per_live']],
                $this->monthlyActive($merchant) => ['monthly', 0],
                $this->trialLeft($merchant) > 0 => ['trial', (int) $s['trial_cards']],
                default => ['single', (int) $s['cards_per_live']],
            };
            if (in_array($plan, ['offered', 'single'], true)) {
                $this->charge($merchant, $user, 'live', "Live « {$live->name} »", (int) $s['price_live'], (int) $s['cards_per_live'], $live);
            }
            $live->forceFill(['status' => Live::LIVE, 'plan' => $plan, 'ai_cards_included' => $included, 'started_at' => now()])->save();

            return $live;
        });
    }

    public function subscribeMonthly(Merchant $merchant, User $user): Merchant
    {
        $this->ensureEnabled();
        if ($this->monthlyActive($merchant)) {
            throw new BusinessRuleException('Votre forfait live est déjà en cours.');
        }
        $s = $this->settings();
        DB::transaction(function () use ($merchant, $user, $s) {
            $this->charge($merchant, $user, 'monthly', 'Forfait live du mois', (int) $s['price_month'], (int) $s['cards_per_month']);
            $merchant->forceFill(['live_plan_until' => now()->addDays(30)])->save();
        });

        return $merchant->refresh();
    }

    public function buyPack(Merchant $merchant, User $user): Merchant
    {
        $this->ensureEnabled();
        $s = $this->settings();
        DB::transaction(function () use ($merchant, $user, $s) {
            $this->charge($merchant, $user, 'pack', "{$s['pack_cards']} fiches produit IA", (int) $s['pack_price'], (int) $s['pack_cards']);
            $merchant->increment('live_extra_cards', (int) $s['pack_cards']);
        });

        return $merchant->refresh();
    }

    /**
     * Frais : débités sur le compte de l'e-commerçante (retenus au prochain reversement), sauf si l'agence offre le
     * live. Part de Jibiat : tout quand l'agence offre, sinon le prix moins la part laissée à l'agence.
     */
    private function charge(Merchant $merchant, User $user, string $kind, string $label, int $amount, int $cards, ?Live $live = null): LiveCharge
    {
        $offered = (bool) $merchant->company?->live_offered;
        if (! $offered && $amount > 0) {
            MerchantLedgerEntry::create([
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'type' => LedgerEntryType::LiveFee,
                'amount' => -$amount,
                'description' => $label,
                'created_by' => $user->id,
            ]);
        }
        $share = $offered ? 0 : (int) $this->settings()['agency_share'];

        return LiveCharge::create([
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'live_id' => $live?->id,
            'kind' => $kind,
            'label' => $label,
            'amount' => $amount,
            'platform_amount' => (int) round($amount * (100 - $share) / 100),
            'offered_by_company' => $offered,
            'cards' => $cards,
        ]);
    }

    // ── Pendant le live ───────────────────────────────────────────────────────────────────────────

    /**
     * Commande prise pendant le live : course créée chez l'agence, ou liste d'attente si le produit est épuisé.
     *
     * @param  array{code: string, quantity?: int, recipient_name: string, recipient_phone: string, delivery_zone_id: int, delivery_address?: ?string}  $data
     */
    public function addOrder(Live $live, User $user, array $data): LiveOrder
    {
        if ($live->status !== Live::LIVE) {
            throw new BusinessRuleException('Démarrez le live pour enregistrer des commandes.');
        }
        $code = strtoupper(preg_replace('/\s+/', '', $data['code']));
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        return DB::transaction(function () use ($live, $user, $data, $code, $quantity) {
            // Live à un seul produit : la cliente écrit « JE PRENDS »
            if (in_array($code, ['JEPRENDS', 'JEPREND'], true) && $live->items()->count() === 1) {
                $code = $live->items()->value('code');
            }
            $item = LiveItem::query()->where('live_id', $live->id)->where('code', $code)->lockForUpdate()->first()
                ?? throw new BusinessRuleException("Le code {$code} n'existe pas dans ce live.", 'code');
            $phone = PhoneNumber::normalize($data['recipient_phone']) ?? $data['recipient_phone'];

            if ($item->remaining() < $quantity) {
                return LiveOrder::create([
                    'live_id' => $live->id, 'live_item_id' => $item->id, 'customer_name' => $data['recipient_name'],
                    'customer_phone' => $phone, 'quantity' => $quantity, 'status' => LiveOrder::WAITING, 'source' => $data['source'] ?? 'manual',
                ]);
            }

            $merchant = $live->merchant()->firstOrFail();
            $product = $item->product;
            $tracked = $product && $product->levels()->exists()
                && (int) $product->levels()->get()->sum(fn ($l) => $l->available()) >= $quantity;
            $order = $this->orders->create($user, $merchant, [
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $phone,
                'delivery_zone_id' => $data['delivery_zone_id'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'merchant_note' => "Live « {$live->name} »",
                'items' => [[
                    'product_id' => $tracked ? $product->id : null,
                    'label' => $item->label(),
                    'quantity' => $quantity,
                    'unit_price' => $item->price,
                ]],
            ], 'live');
            $item->increment('sold', $quantity);

            return LiveOrder::create([
                'live_id' => $live->id, 'live_item_id' => $item->id, 'order_id' => $order->id, 'customer_name' => $data['recipient_name'],
                'customer_phone' => $phone, 'quantity' => $quantity, 'status' => LiveOrder::ORDERED, 'source' => $data['source'] ?? 'manual',
            ]);
        });
    }

    public function end(Live $live): Live
    {
        if ($live->status === Live::LIVE) {
            $live->forceFill(['status' => Live::ENDED, 'ended_at' => now()])->save();
        }

        return $live;
    }

    /**
     * Écran du live : produits (reste), commandes, conseils calculés (sans IA), clientes à risque.
     *
     * @return array<string, mixed>
     */
    public function board(Live $live): array
    {
        $s = $this->settings();
        $live->load(['items.product', 'orders' => fn ($q) => $q->latest('id')->with('item:id,code,name,variant,price')]);
        $orders = $live->orders;
        $ordered = $orders->where('status', LiveOrder::ORDERED);
        $now = now();

        $advices = [];
        foreach ($live->items as $item) {
            $own = $ordered->where('live_item_id', $item->id);
            $remaining = $item->remaining();
            if ($remaining === 0) {
                $advices[] = ['level' => 'alert', 'code' => $item->code, 'text' => "{$item->code} épuisé. Annoncez-le."];
            } elseif ($remaining <= (int) $s['low_stock'] && $own->isNotEmpty()) {
                $advices[] = ['level' => 'info', 'code' => $item->code, 'text' => "{$item->code} : il en reste {$remaining}. Dites-le !"];
            }
            $recent = $own->filter(fn ($o) => $o->created_at->gte($now->copy()->subMinutes(5)))->count();
            if ($recent >= 3) {
                $advices[] = ['level' => 'good', 'code' => $item->code, 'text' => "{$item->code} : {$recent} commandes en 5 min. Ça marche !"];
            }
        }
        // Un seul produit « qui dort » : celui sans commande depuis le plus longtemps
        if ($live->status === Live::LIVE && $live->started_at && $live->started_at->lte($now->copy()->subMinutes((int) $s['idle_minutes']))) {
            $idle = $live->items->filter(fn ($i) => $i->remaining() > 0)
                ->map(fn ($i) => ['item' => $i, 'last' => $ordered->where('live_item_id', $i->id)->max('created_at') ?? $live->started_at])
                ->filter(fn ($r) => $r['last']->lte($now->copy()->subMinutes((int) $s['idle_minutes'])))
                ->sortBy('last')->first();
            if ($idle) {
                $advices[] = ['level' => 'info', 'code' => $idle['item']->code, 'text' => "{$idle['item']->code} : rien depuis {$s['idle_minutes']} min. Faites une promo."];
            }
        }

        // Clientes qui ont souvent refusé leur colis chez cette entreprise
        $phones = $orders->pluck('customer_phone')->unique()->values();
        $refusals = $phones->isEmpty() ? collect() : Order::forCompany($live->company_id)->whereIn('recipient_phone', $phones)
            ->whereIn('status', self::REFUSED)->selectRaw('recipient_phone, COUNT(*) AS n')->groupBy('recipient_phone')->pluck('n', 'recipient_phone');

        return [
            'live' => $this->present($live),
            'items' => $live->items->map(fn (LiveItem $i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'code' => $i->code, 'name' => $i->name, 'variant' => $i->variant,
                'label' => $i->label(), 'price' => $i->price, 'quantity' => $i->quantity,
                'sold' => $i->sold, 'remaining' => $i->remaining(), 'photo_url' => $i->product?->photoUrl(),
            ])->values(),
            'orders' => $orders->map(fn (LiveOrder $o) => [
                'id' => $o->id, 'code' => $o->item?->code, 'name' => $o->item?->label(), 'customer_name' => $o->customer_name,
                'customer_phone' => $o->customer_phone, 'quantity' => $o->quantity, 'status' => $o->status, 'order_id' => $o->order_id,
                'source' => $o->source,
                'created_at' => $o->created_at,
                'refusals' => ($n = (int) ($refusals[$o->customer_phone] ?? 0)) >= (int) $s['risk_refusals'] ? $n : 0,
            ])->values(),
            'advices' => $advices,
            'totals' => [
                'orders' => $ordered->count(),
                'amount' => (int) $ordered->sum(fn ($o) => $o->quantity * ($o->item?->price ?? 0)),
                'waiting' => $orders->where('status', LiveOrder::WAITING)->count(),
            ],
        ];
    }

    /**
     * Bilan après le live : ventes, produits, livraisons, meilleur moment, actions proposées.
     *
     * @return array<string, mixed>
     */
    public function summary(Live $live): array
    {
        $board = $this->board($live);
        $ordered = $live->orders->where('status', LiveOrder::ORDERED);
        $orderIds = $ordered->pluck('order_id')->filter();
        $statuses = Order::forCompany($live->company_id)->whereKey($orderIds)->pluck('status')->map(fn ($s) => $s->value);
        $start = $live->started_at;
        $byMinute = $start ? $ordered->groupBy(fn ($o) => intdiv(max(0, (int) $start->diffInMinutes($o->created_at)), 5) * 5)->map->count() : collect();
        $peak = $byMinute->sortDesc()->keys()->first();

        return [
            ...$board,
            'duration_minutes' => $start ? (int) $start->diffInMinutes($live->ended_at ?? now()) : 0,
            'deliveries' => [
                'created' => $orderIds->count(),
                'delivered' => $statuses->filter(fn ($s) => $s === OrderStatus::Delivered->value)->count(),
                'refused' => $statuses->filter(fn ($s) => in_array($s, array_map(fn ($x) => $x->value, self::REFUSED), true))->count(),
            ],
            'best_item' => collect($board['items'])->sortByDesc(fn ($i) => $i['sold'] * $i['price'])->first(),
            'peak_minute' => $peak,
            'new_customers' => $ordered->pluck('customer_phone')->unique()->filter(fn ($p) => ! Order::forCompany($live->company_id)
                ->where('merchant_id', $live->merchant_id)->where('recipient_phone', $p)->where('created_at', '<', $live->started_at ?? now())->exists())->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(Live $live): array
    {
        return [
            'id' => $live->id, 'name' => $live->name, 'status' => $live->status, 'plan' => $live->plan,
            'started_at' => $live->started_at, 'ended_at' => $live->ended_at,
            'ai_cards_used' => $live->ai_cards_used, 'items_count' => $live->items_count ?? $live->items()->count(),
        ];
    }

    // ── Plateforme ────────────────────────────────────────────────────────────────────────────────

    /**
     * Part de Jibiat à facturer à l'entreprise : frais pas encore facturés, jusqu'à la fin de la période.
     *
     * @return array{amount: int, lines: list<array<string, mixed>>, ids: list<int>}
     */
    public function billable(Company $company, CarbonImmutable $last): array
    {
        $charges = LiveCharge::forCompany($company->id)->whereNull('platform_invoice_id')->where('platform_amount', '>', 0)
            ->where('created_at', '<', $last->addDay()->startOfDay()->utc())->with('merchant:id,business_name')->get();
        $lines = $charges->groupBy('kind')->map(fn (Collection $group, string $kind) => [
            'kind' => $kind,
            'label' => match ($kind) {
                'live' => 'Outil live · '.$group->count().' live(s)',
                'monthly' => 'Outil live · '.$group->count().' forfait(s) du mois',
                default => 'Outil live · '.$group->count().' pack(s) de fiches IA',
            }.($group->contains('offered_by_company', true) ? ' (dont offerts par vous)' : ''),
            'count' => $group->count(),
            'amount' => (int) $group->sum('platform_amount'),
        ])->values()->all();

        return ['amount' => (int) $charges->sum('platform_amount'), 'lines' => $lines, 'ids' => $charges->modelKeys()];
    }

    public function platformCostThisMonth(): int
    {
        return (int) LiveAiCard::query()->withoutGlobalScopes()->where('created_at', '>=', now()->startOfMonth())->sum('cost_millis');
    }

    /** Tableau de suivi de la console @return array<string, mixed> */
    public function overview(): array
    {
        $since = now()->startOfMonth();
        $charges = LiveCharge::query()->withoutGlobalScopes()->where('created_at', '>=', $since)->get();
        $cards = LiveAiCard::query()->withoutGlobalScopes()->where('created_at', '>=', $since)->get(['cost_millis', 'merchant_id']);
        $lives = Live::query()->withoutGlobalScopes()->where('started_at', '>=', $since);
        $liveOrders = LiveOrder::query()->whereIn('live_id', (clone $lives)->select('id'))->where('status', LiveOrder::ORDERED);

        return [
            'lives' => (clone $lives)->count(),
            'merchants' => (clone $lives)->distinct()->count('merchant_id'),
            'orders' => (clone $liveOrders)->count(),
            'paid_by_merchants' => (int) $charges->where('offered_by_company', false)->sum('amount'),
            'platform_amount' => (int) $charges->sum('platform_amount'),
            'cards' => $cards->count(),
            'ai_cost' => (int) round($cards->sum('cost_millis') / 1000),
            'card_cost_avg' => $cards->isEmpty() ? null : (int) round($cards->avg('cost_millis') / 1000),
            'by_company' => Live::query()->withoutGlobalScopes()->where('started_at', '>=', $since)
                ->join('companies', 'companies.id', '=', 'lives.company_id')
                ->selectRaw('companies.id, companies.name, COUNT(lives.id) AS lives')->groupBy('companies.id', 'companies.name')
                ->orderByDesc('lives')->limit(20)->get(),
        ];
    }

    private function ensureEnabled(): void
    {
        if (! $this->settings()['enabled']) {
            throw new BusinessRuleException('L\'outil live n\'est pas disponible pour le moment.');
        }
    }
}
