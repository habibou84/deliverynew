<?php

namespace App\Services\Live;

use App\Enums\FeePayer;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendLiveWhatsApp;
use App\Models\Live;
use App\Models\LiveChat;
use App\Models\LiveItem;
use App\Models\LiveOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Zone;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use App\Services\Messaging\WhatsAppConnection;
use App\Services\Pricing\PricingService;
use App\Services\WhatsApp\ZoneMatcher;
use App\Support\PhoneNumber;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commandes du live par WhatsApp, sans intervention de l'e-commerçante.
 *
 * Son numéro WhatsApp Business est relié à Jibiat par la connexion officielle de Meta (fenêtre « Connecter
 * WhatsApp », elle garde son application WhatsApp Business). Option de la console, désactivée par défaut : le
 * relier par code d'association ou QR code (session « merchant-34 » du service whatsapp-bridge, connexion non
 * officielle, risque de suspension du numéro). Pendant un live en cours, un client envoie le code d'un produit (« A2 », « A2 x2 », « JE PRENDS » quand
 * il n'y a qu'un article) ; Jibiat lui demande son nom et son quartier (ou reprend ceux de sa dernière commande),
 * puis envoie le récapitulatif avec la photo : 1 Je confirme, 2 Ajouter un article, 3 Changer l'adresse, 4 Annuler.
 * Rien ne part à l'agence avant « 1 » : la course est alors créée (une seule pour tous les articles), ou le client
 * passe en liste d'attente si c'est épuisé, et il reçoit les photos de sa commande. Sans réponse pendant
 * CHAT_MINUTES, la commande est oubliée (les articles restent disponibles pour les autres).
 * Les autres messages (conversations personnelles) sont ignorés.
 */
class LiveWhatsApp
{
    // Une commande pas confirmée est oubliée au bout de ce délai (les articles restent disponibles)
    public const CHAT_MINUTES = 30;

    // Photos envoyées après « Je confirme » (au-delà, la liste suffit)
    private const PHOTOS = 4;

    private const LINE = '━━━━━━━━━━━━';

    private const YES = ['oui', 'ok', 'okay', 'd accord', 'dac', 'yes', 'ouais', 'oui oui', 'oui merci', 'c est ca', 'exact', 'c est bon'];

    /** Numéro officiel de la conversation en cours (null : session du code d'association / QR code) */
    private ?int $accountId = null;

    public function __construct(
        private readonly WhatsAppBridge $bridge,
        private readonly LiveStudio $studio,
        private readonly ZoneMatcher $zones,
        private readonly PricingService $pricing,
    ) {}

    /** Option de la console : proposer aussi le code d'association / QR code (non officiel) */
    public function qrOption(): bool
    {
        return (bool) $this->studio->settings()['qr_whatsapp'];
    }

    /** Numéro WhatsApp Business de l'e-commerçante relié officiellement (Meta) */
    public function official(Merchant $merchant): ?WhatsAppAccount
    {
        $account = WhatsAppAccount::forMerchant($merchant->id);

        return $account !== null && $account->isLive() ? $account : null;
    }

    /** Relié par code d'association / QR code (option active, pas de connexion officielle) */
    public function usesQr(Merchant $merchant): bool
    {
        return $merchant->live_wa_status === 'connected' && $this->qrOption() && $this->official($merchant) === null;
    }

    /** Numéro de la carte du live : celui qui reçoit les commandes automatiques, sinon celui de la boutique */
    public function cardPhone(Merchant $merchant): ?string
    {
        if ($official = $this->official($merchant)) {
            return PhoneNumber::normalize($official->display_phone) ?? $official->display_phone;
        }

        return $this->usesQr($merchant) && $merchant->live_wa_phone ? $merchant->live_wa_phone : $merchant->messagingPhone();
    }

    /** Délier le numéro relié officiellement */
    public function disconnectOfficial(Merchant $merchant): void
    {
        WhatsAppAccount::forMerchant($merchant->id)?->delete();
    }

    public static function session(Merchant|int $merchant): string
    {
        return 'merchant-'.($merchant instanceof Merchant ? $merchant->id : $merchant);
    }

    public static function merchantId(string $session): ?int
    {
        return preg_match('/^merchant-(\d+)$/', $session, $m) ? (int) $m[1] : null;
    }

    // ── Connexion du WhatsApp de l'e-commerçante ──────────────────────────────────────────────────────

    /**
     * Démarre la liaison : code d'association (si un numéro est donné) et QR code.
     *
     * @return array<string, mixed>
     *
     * @throws MessagingException
     */
    public function connect(Merchant $merchant, ?string $phone): array
    {
        if (! $this->qrOption()) {
            throw new MessagingException('Reliez votre WhatsApp par la connexion officielle.');
        }
        // Numéro complet avec l'indicatif (07… → 22507…), exigé par WhatsApp pour le code d'association
        $state = $this->bridge->start(self::session($merchant), $phone ? (PhoneNumber::normalize($phone) ?? $phone) : null);
        $this->remember($merchant, $state);

        return $this->state($merchant, $state);
    }

    /** @return array<string, mixed> */
    public function status(Merchant $merchant): array
    {
        $state = null;
        if ($this->qrOption() && WhatsAppBridge::configured() && $merchant->live_wa_status !== null) {
            try {
                $state = $this->bridge->status(self::session($merchant));
                $this->remember($merchant, $state);
            } catch (MessagingException) {
                $state = null;
            }
        }

        return $this->state($merchant, $state);
    }

    /** @throws MessagingException */
    public function disconnect(Merchant $merchant): void
    {
        $this->bridge->logout(self::session($merchant));
        $merchant->forceFill(['live_wa_status' => 'disconnected'])->save();
    }

    /**
     * État de la session envoyé par le service (connecté, déconnecté depuis le téléphone).
     *
     * @param  array<string, mixed>  $event
     */
    public function onStatus(int $merchantId, array $event): void
    {
        $merchant = Merchant::withoutGlobalScopes()->find($merchantId);
        if ($merchant === null) {
            return;
        }
        match ((string) ($event['status'] ?? '')) {
            'connected' => $merchant->forceFill([
                'live_wa_status' => 'connected',
                'live_wa_phone' => $event['phone'] ?? $merchant->live_wa_phone,
                'live_wa_connected_at' => now(),
            ])->save(),
            'logged_out' => $merchant->forceFill(['live_wa_status' => 'disconnected'])->save(),
            default => null,
        };
    }

    private function remember(Merchant $merchant, array $state): void
    {
        $connected = ($state['status'] ?? null) === 'connected';
        $merchant->forceFill([
            'live_wa_status' => $connected ? 'connected' : ($merchant->live_wa_status === 'connected' && $state['status'] !== 'logged_out' ? 'connected' : 'pending'),
            'live_wa_phone' => $connected ? ($state['phone'] ?? $merchant->live_wa_phone) : $merchant->live_wa_phone,
            'live_wa_connected_at' => $connected && $merchant->live_wa_status !== 'connected' ? now() : $merchant->live_wa_connected_at,
        ])->save();
    }

    /**
     * Commandes automatiques : connexion officielle (par défaut) ou code d'association / QR code (option).
     *
     * @return array<string, mixed>
     */
    public function state(Merchant $merchant, ?array $bridge = null): array
    {
        $official = $this->official($merchant);
        $qr = $this->usesQr($merchant);

        return [
            'mode' => $official ? 'official' : ($qr ? 'qr' : 'none'),
            'auto' => $official !== null || $qr,
            'official' => [
                'available' => WhatsAppConnection::signupAvailable(),
                'connected' => $official !== null,
                'phone' => $official ? (PhoneNumber::normalize($official->display_phone) ?? $official->display_phone) : null,
                'name' => $official?->verified_name,
            ],
            'qr_option' => $this->qrOption(),
            'available' => $this->qrOption() && WhatsAppBridge::configured(),
            'connected' => $qr,
            'phone' => $merchant->live_wa_phone,
            'connected_at' => $merchant->live_wa_connected_at,
            'qr' => $bridge['qr'] ?? null,
            'pairing_code' => $bridge['pairing_code'] ?? null,
        ];
    }

    // ── Conversation avec les clients ────────────────────────────────────────────────────────────────

    /**
     * Message reçu par code d'association / QR code (option de la console). Ignoré si le numéro est relié
     * officiellement : la connexion officielle répond seule.
     */
    public function onMessage(int $merchantId, string $from, ?string $pushName, ?string $text): void
    {
        $merchant = Merchant::withoutGlobalScopes()->with('company')->find($merchantId);
        if ($merchant === null || ! $this->usesQr($merchant)) {
            return;
        }
        $this->accountId = null;
        $this->receive($merchant, $from, $pushName, $text);
    }

    /**
     * Message reçu sur le numéro WhatsApp Business relié officiellement (webhook de Meta).
     */
    public function onOfficial(WhatsAppAccount $account, string $from, ?string $pushName, ?string $text): void
    {
        $merchant = $account->isMerchant() ? Merchant::withoutGlobalScopes()->with('company')->find($account->owner_id) : null;
        if ($merchant === null) {
            return;
        }
        $this->accountId = $account->id;
        $this->receive($merchant, $from, $pushName, $text);
    }

    private function receive(Merchant $merchant, string $from, ?string $pushName, ?string $text): void
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        $live = Live::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('status', Live::LIVE)->latest('id')->first();
        if ($live === null) {
            return;
        }
        $phone = PhoneNumber::normalize($from) ?? $from;

        $chat = LiveChat::query()->where('live_id', $live->id)->where('phone', $phone)->first();
        if ($chat && $chat->expires_at->isPast()) {
            $chat->delete();
            $chat = null;
        }

        // Un code ajoute l'article à la commande en cours (ou en commence une)
        $pick = $this->pick($live, $text, strict: $chat !== null);
        if ($pick !== null) {
            $this->choose($merchant, $live, $chat, $phone, $pushName, $pick['item'], $pick['quantity']);

            return;
        }
        if ($chat === null) {
            // Message ordinaire (pas un code) : conversation personnelle, pas de réponse automatique
            return;
        }
        $chat->forceFill(['last_text' => mb_substr($text, 0, 300), 'expires_at' => now()->addMinutes(self::CHAT_MINUTES)])->save();
        $answer = $this->answer($text);

        // Récapitulatif envoyé : un chiffre (ou un mot) choisit la suite
        if (in_array($chat->step, [LiveChat::CONFIRM, LiveChat::ADD, LiveChat::CANCEL], true)) {
            if ($chat->step === LiveChat::CANCEL && $answer === 4) {
                $chat->delete();
                $this->reply($merchant, $phone, "❌ *COMMANDE ANNULÉE*\n\nVotre commande est annulée. Merci et à bientôt sur nos lives !");

                return;
            }
            match ($answer) {
                1 => $this->order($merchant, $live, $chat, $chat->customer_name ?: ($chat->push_name ?: 'Client'), (int) $chat->zone_id),
                2 => $this->ask($merchant, $chat, LiveChat::ADD, "➕ Envoyez le *code de l'article* à ajouter\n_Exemple : A3_"),
                3 => $this->ask($merchant, $chat, LiveChat::DETAILS, "📍 *NOUVELLE ADRESSE*\n\n✍️ Écrivez *votre nom, votre commune et votre quartier*\n_Exemple : Awa, Cocody Angré 8e tranche_"),
                4 => $this->ask($merchant, $chat, LiveChat::CANCEL, $this->buttons()
                    ? "⚠️ *VOUS VOULEZ ANNULER ?*\n\nPour annuler la commande, touchez *Oui, annuler*."
                    : "⚠️ *VOUS VOULEZ ANNULER ?*\n\nPour annuler la commande, répondez *4* ou *ANNULER* encore une fois.",
                    [['id' => '4', 'title' => '❌ Oui, annuler']]),
                default => $chat->step === LiveChat::ADD
                    ? $this->reply($merchant, $phone, $this->buttons()
                        ? "Envoyez le *code de l'article* à ajouter (exemple : A3), ou touchez *Je confirme*."
                        : "Envoyez le *code de l'article* à ajouter (exemple : A3), ou répondez *1* pour confirmer votre commande.",
                        null, [['id' => '1', 'title' => '✅ Je confirme']])
                    : $this->reply($merchant, $phone, $this->menu()[0], null, $this->menu()[1]),
            };
            if ($chat->step === LiveChat::CANCEL && $answer !== null && $answer !== 4 && $chat->exists) {
                $chat->forceFill(['step' => LiveChat::CONFIRM])->save();
            }

            return;
        }

        // Quartier proposé : le client répond par son chiffre (ou réécrit son adresse)
        if ($chat->step === LiveChat::ZONE && preg_match('/^\s*([1-9])\s*$/', $text, $m)) {
            $zoneId = $chat->zone_choices[(int) $m[1] - 1] ?? null;
            if ($zoneId !== null && $this->companyZones($merchant)->contains('id', $zoneId)) {
                $chat->forceFill(['zone_id' => $zoneId, 'zone_choices' => null, 'step' => LiveChat::CONFIRM, 'misses' => 0])->save();
                $this->recap($merchant, $chat);

                return;
            }
        }

        // Nom et quartier attendus (même mal écrits : « yopougn sipores »)
        $found = $this->zonesIn($merchant, $text);
        if ($found->isEmpty()) {
            $this->reply($merchant, $phone, "Je n'ai pas reconnu le quartier 🤔\n✍️ Écrivez *votre nom, votre commune et votre quartier*\n_Exemple : Awa, Cocody Angré_");
            // Quartier pas reconnu : visible dans l'onglet « Messages », la vendeuse peut le choisir elle-même
            $chat->forceFill(['misses' => min(255, $chat->misses + 1)])->save();

            return;
        }
        $name = $this->nameIn($text, $found) ?? $chat->customer_name ?? $chat->push_name ?? 'Client';
        if ($found->count() > 1) {
            $chat->forceFill([
                'customer_name' => $name, 'zone_choices' => $found->pluck('id')->all(), 'step' => LiveChat::ZONE, 'misses' => 0,
            ])->save();
            if ($this->buttons()) {
                $this->reply($merchant, $phone, "📍 *QUEL EST VOTRE QUARTIER ?*\n\nTouchez votre quartier, ou écrivez-le à nouveau.", null,
                    $found->values()->map(fn (Zone $z, int $i) => [
                        'id' => (string) ($i + 1), 'title' => $z->parent ? "{$z->name} ({$z->parent->name})" : $z->name, 'description' => $z->parent?->name,
                    ])->all());

                return;
            }
            $list = $found->values()->map(fn (Zone $z, int $i) => '*'.($i + 1).'* '.$z->fullName())->implode("\n");
            $this->reply($merchant, $phone, "📍 *QUEL EST VOTRE QUARTIER ?*\n\n{$list}\n\n👉 Répondez avec le chiffre, ou écrivez à nouveau votre quartier.");

            return;
        }
        $chat->forceFill([
            'customer_name' => $name, 'zone_id' => $found->first()->id, 'zone_choices' => null, 'step' => LiveChat::CONFIRM, 'misses' => 0,
        ])->save();
        $this->recap($merchant, $chat);
    }

    /** « 1 », « 1 ✅ », « oui », « je confirme », « annuler »… → 1 à 4 ; autre chose → null */
    private function answer(string $text): ?int
    {
        $norm = ZoneMatcher::normalize($text);
        if (preg_match('/^\s*([1-4])\b/', $text, $m)) {
            return (int) $m[1];
        }

        return match (true) {
            in_array($norm, self::YES, true), in_array($norm, ['je confirme', 'confirme', 'confirmer', 'valider', 'je valide'], true) => 1,
            in_array($norm, ['ajouter', 'ajout'], true) => 2,
            in_array($norm, ['adresse', 'changer', 'changer l adresse', 'changer adresse'], true) => 3,
            in_array($norm, ['annuler', 'annule', 'non'], true) => 4,
            default => null,
        };
    }

    /** @param  list<array{id: string, title: string}>  $choices */
    private function ask(Merchant $merchant, LiveChat $chat, string $step, string $text, array $choices = []): void
    {
        $chat->forceFill(['step' => $step])->save();
        $this->reply($merchant, $chat->phone, $text, null, $choices);
    }

    /**
     * Produit demandé : code (« A2 », « a 2 », « A2 x3 ») ou « JE PRENDS » quand le live n'a qu'un article.
     * Pendant une conversation, seul un message qui n'est qu'un code compte (« Cité A2 » reste une adresse).
     *
     * @return array{item: LiveItem, quantity: int}|null
     */
    private function pick(Live $live, string $text, bool $strict): ?array
    {
        $upper = Str::upper(Str::ascii($text));
        $items = LiveItem::query()->where('live_id', $live->id)->get();

        if (preg_match('/^\s*(?:JE\s*(?:LE\s*|LA\s*)?PRENDS?|MOI)\s*[!.]*\s*(?:[X*]\s*(\d{1,2}))?\s*$/', $upper, $m) && $items->count() === 1) {
            return ['item' => $items->first(), 'quantity' => max(1, (int) ($m[1] ?? 1))];
        }

        $pattern = $strict
            ? '/^\s*(?:(\d{1,2})\s*[X*]?\s*)?([A-Z])\s?(\d{1,2})\s*(?:[X*]\s*(\d{1,2}))?\s*[!.]*\s*$/'
            : '/(?:^|[^A-Z0-9])(?:(\d{1,2})\s*[X*]\s*)?([A-Z])\s?(\d{1,2})(?:\s*[X*]\s*(\d{1,2}))?(?=$|[^A-Z0-9])/';
        if (! preg_match($pattern, $upper, $m)) {
            return null;
        }
        $item = $items->firstWhere('code', $m[2].$m[3]);
        if ($item === null) {
            return null;
        }

        return ['item' => $item, 'quantity' => max(1, min(20, (int) (($m[4] ?? '') ?: ($m[1] ?? '') ?: 1)))];
    }

    /**
     * Article demandé : ajouté à la commande en cours (ou nouvelle commande). Adresse connue (dernière commande chez
     * cette boutique, ou déjà donnée) : récapitulatif tout de suite ; sinon on demande le nom et le quartier.
     */
    private function choose(Merchant $merchant, Live $live, ?LiveChat $chat, string $phone, ?string $pushName, LiveItem $item, int $quantity): void
    {
        $chat ??= new LiveChat(['live_id' => $live->id, 'phone' => $phone]);
        $cart = $chat->lines();
        $found = false;
        foreach ($cart as $k => $line) {
            if ($line['item_id'] === $item->id) {
                // Même article redemandé pendant l'ajout : une pièce de plus ; sinon la quantité demandée
                $cart[$k]['quantity'] = min(20, $chat->step === LiveChat::ADD ? $line['quantity'] + $quantity : $quantity);
                $found = true;
            }
        }
        if (! $found) {
            $cart[] = ['item_id' => $item->id, 'quantity' => $quantity];
        }

        // Client déjà livré par cette boutique : son nom et son quartier sont repris
        if (! $chat->zone_id) {
            $last = Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('recipient_phone', $phone)
                ->whereNotNull('delivery_zone_id')->latest('id')->first(['recipient_name', 'delivery_zone_id']);
            if ($last && Zone::withoutGlobalScopes()->whereKey($last->delivery_zone_id)->exists()) {
                $chat->customer_name = $last->recipient_name;
                $chat->zone_id = $last->delivery_zone_id;
            }
        }
        $updated = $chat->exists;
        $chat->fill([
            'push_name' => $pushName ? mb_substr($pushName, 0, 120) : $chat->push_name,
            'live_item_id' => $item->id,
            'quantity' => $quantity,
            'cart' => $cart,
            'step' => $chat->zone_id ? LiveChat::CONFIRM : LiveChat::DETAILS,
            'expires_at' => now()->addMinutes(self::CHAT_MINUTES),
        ])->save();

        if ($chat->zone_id) {
            $this->recap($merchant, $chat, $updated, $item);

            return;
        }
        $price = $this->money($item->price * $quantity);
        $line = ($quantity > 1 ? "{$quantity} × " : '')."*{$item->code}* {$item->label()} : *{$price}*";
        $soldOut = $item->remaining() < $quantity;
        $this->reply($merchant, $phone, ($soldOut ? "😔 {$line}\nC'est *épuisé* pour le moment : vous serez sur la *liste d'attente*." : "🛍️ {$line}")
            ."\n\n📍 *VOTRE ADRESSE*\n✍️ Écrivez *votre nom, votre commune et votre quartier*\n_Exemple : Awa, Cocody Angré 8e tranche_");
    }

    /**
     * Récapitulatif à confirmer : articles, nom et quartier, livraison, total, et la photo de l'article demandé.
     */
    private function recap(Merchant $merchant, LiveChat $chat, bool $updated = false, ?LiveItem $photoOf = null): void
    {
        $items = LiveItem::query()->whereIn('id', array_column($chat->lines(), 'item_id'))->with('product')->get()->keyBy('id');
        $lines = '';
        $total = 0;
        $waiting = false;
        foreach ($chat->lines() as $line) {
            $item = $items->get($line['item_id']);
            if ($item === null) {
                continue;
            }
            $soldOut = $item->remaining() < $line['quantity'];
            $waiting = $waiting || $soldOut;
            $amount = $item->price * $line['quantity'];
            $total += $soldOut ? 0 : $amount;
            $lines .= "{$line['quantity']} × *{$item->code}* {$item->label()} : ".$this->money($amount).($soldOut ? ' _(épuisé : liste d\'attente)_' : '')."\n";
        }
        $zone = Zone::withoutGlobalScopes()->find($chat->zone_id);
        $fee = $total > 0 ? $this->deliveryFee($merchant, $zone) : null;
        $photo = $items->get($photoOf?->id ?? $chat->live_item_id)?->product?->photoUrl();

        $text = ($updated ? "✅ *COMMANDE MISE À JOUR*\n\n" : "🧾 *VOTRE COMMANDE*\n\n")
            .$lines
            ."📍 *{$zone?->name}* · {$chat->customer_name}\n"
            .($fee !== null ? '🚚 Livraison : '.$this->money($fee)."\n" : '')
            .($total > 0 ? '💰 *À payer à la livraison : '.$this->money($total + ($fee ?? 0))."*\n" : '')
            .($photo ? "📷 _Vérifiez la photo avant de confirmer_\n" : '')
            .self::LINE."\n".$this->menu()[0];
        $this->reply($merchant, $chat->phone, $text, $photo ? $this->absolute($merchant, $photo) : null, $this->menu()[1]);
    }

    /** Frais de livraison payés par le client (réglage de l'e-commerçant), sinon null : rien à ajouter au total */
    private function deliveryFee(Merchant $merchant, ?Zone $zone): ?int
    {
        if ($zone === null || $merchant->default_fee_payer !== FeePayer::Recipient || ! $merchant->pickup_zone_id) {
            return null;
        }
        try {
            $pickup = Zone::withoutGlobalScopes()->find($merchant->pickup_zone_id);

            return $pickup ? $this->pricing->quote($merchant, $pickup, $zone)->total() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Adresse complète d'une photo (Meta la télécharge depuis le site de l'entreprise) */
    private function absolute(Merchant $merchant, string $path): string
    {
        return rtrim($merchant->company ? Tenancy::baseUrl($merchant->company) : (string) config('app.url'), '/').$path;
    }

    /**
     * La vendeuse termine elle-même une commande en cours (onglet « Messages ») : elle choisit le quartier (et le nom)
     * quand le message du client n'a pas été reconnu. Le client reçoit la même confirmation.
     */
    public function completeByMerchant(Merchant $merchant, LiveChat $chat, int $zoneId, ?string $name): void
    {
        $live = $chat->live;
        abort_unless($live !== null && $live->merchant_id === $merchant->id && $live->status === Live::LIVE, 422, 'Le live n\'est pas en cours.');
        abort_unless($this->companyZones($merchant)->contains('id', $zoneId), 422, 'Quartier inconnu.');
        // Réponse depuis le même numéro que la conversation
        $this->accountId = $this->official($merchant)?->id;
        $this->order($merchant, $live, $chat, trim((string) $name) ?: ($chat->customer_name ?: $chat->push_name ?: 'Client'), $zoneId);
    }

    /**
     * Conversations en cours du live (commandes commencées par WhatsApp, pas encore terminées)
     *
     * @return list<array<string, mixed>>
     */
    public function chats(Live $live): array
    {
        return LiveChat::query()->where('live_id', $live->id)->where('expires_at', '>', now())->with(['item', 'zone'])
            ->orderByDesc('misses')->latest('updated_at')->limit(50)->get()
            ->map(fn (LiveChat $c) => [
                'id' => $c->id, 'phone' => $c->phone, 'name' => $c->customer_name ?: $c->push_name,
                'step' => $c->step, 'code' => $this->cartText($c, codes: true) ?: $c->item?->code,
                'item' => $this->cartText($c) ?: $c->item?->label(), 'quantity' => $c->quantity,
                'zone' => $c->zone?->name, 'last_text' => $c->last_text, 'stuck' => $c->misses > 0, 'updated_at' => $c->updated_at,
            ])->all();
    }

    /**
     * « 1 Je confirme » (ou la vendeuse qui choisit le quartier) : la commande part à l'agence, une seule course pour
     * les articles disponibles ; liste d'attente pour les épuisés. Puis les photos de la commande.
     */
    private function order(Merchant $merchant, Live $live, LiveChat $chat, string $name, int $zoneId): void
    {
        $items = LiveItem::query()->whereIn('id', array_column($chat->lines(), 'item_id'))->with('product')->get()->keyBy('id');
        $lines = array_values(array_filter($chat->lines(), fn ($l) => $items->has($l['item_id'])));
        $user = User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->orderBy('id')->first();
        if ($lines === [] || $user === null) {
            $chat->delete();

            return;
        }

        try {
            $orders = $this->studio->addCart($live, $user, [
                'items' => array_map(fn ($l) => ['code' => $items[$l['item_id']]->code, 'quantity' => $l['quantity']], $lines),
                'recipient_name' => mb_substr($name, 0, 120),
                'recipient_phone' => $chat->phone,
                'delivery_zone_id' => $zoneId,
                'source' => 'whatsapp',
            ]);
        } catch (BusinessRuleException $e) {
            $this->reply($merchant, $chat->phone, "Désolé, la commande n'a pas pu être enregistrée : {$e->getMessage()}\nLa boutique vous recontacte.");
            $chat->delete();

            return;
        }
        $chat->delete();

        $first = Str::of($name)->explode(' ')->first();
        $ordered = array_values(array_filter($orders, fn (LiveOrder $o) => $o->status !== LiveOrder::WAITING));
        $waiting = array_values(array_filter($orders, fn (LiveOrder $o) => $o->status === LiveOrder::WAITING));
        $waitingText = implode(', ', array_map(fn (LiveOrder $o) => "{$items[$o->live_item_id]->code} {$items[$o->live_item_id]->label()}", $waiting));

        if ($ordered === []) {
            $this->reply($merchant, $chat->phone, "📝 C'est noté, {$first} : vous êtes sur la *liste d'attente* pour {$waitingText}.\nOn vous écrit dès qu'il revient.");

            return;
        }
        $course = Order::withoutGlobalScopes()->find($ordered[0]->order_id, ['id', 'tracking_code', 'cod_amount']);
        $this->reply($merchant, $chat->phone, "🎉 *COMMANDE CONFIRMÉE !*\n\nMerci {$first} 🙏\n"
            .($course?->tracking_code ? "🧾 N° {$course->tracking_code}\n" : '')
            .($course && $course->cod_amount ? '💰 *'.$this->money($course->cod_amount)."* à payer à la livraison\n" : '')
            .'🛵 Le livreur vous appelle avant de passer.'
            .($waiting !== [] ? "\n\n📝 Épuisé pour le moment : {$waitingText}. Vous êtes sur la *liste d'attente*." : ''));

        // Les photos de la commande, en grand
        $shown = 0;
        foreach ($ordered as $o) {
            $photo = $items[$o->live_item_id]->product?->photoUrl();
            if ($photo === null || $shown >= self::PHOTOS) {
                continue;
            }
            $caption = ($shown === 0 ? '📷 *Voici les photos de votre commande'.($course?->tracking_code ? " N° {$course->tracking_code}" : '')."*\n" : '')
                ."{$items[$o->live_item_id]->code} {$items[$o->live_item_id]->label()}";
            $this->reply($merchant, $chat->phone, $caption, $this->absolute($merchant, $photo));
            $shown++;
        }
    }

    /** Articles d'une conversation : « A1, A3 » (codes) ou « Robe wax · M, Sac » */
    private function cartText(LiveChat $chat, bool $codes = false): string
    {
        $items = LiveItem::query()->whereIn('id', array_column($chat->lines(), 'item_id'))->get()->keyBy('id');

        return collect($chat->lines())->map(function ($l) use ($items, $codes) {
            $item = $items->get($l['item_id']);

            return $item ? ($codes ? $item->code : ($l['quantity'] > 1 ? "{$l['quantity']} × " : '').$item->label()) : null;
        })->filter()->implode(', ');
    }

    /** @return Collection<int, Zone> */
    private function zonesIn(Merchant $merchant, string $text): Collection
    {
        return $this->zones->guess($text, $this->companyZones($merchant));
    }

    /**
     * Nom du client : le message sans le quartier (« Awa, Yopougon Niangon » → « Awa »), même mal écrit.
     *
     * @param  Collection<int, Zone>  $zones
     */
    private function nameIn(string $text, Collection $zones): ?string
    {
        $words = $zones->flatMap(fn (Zone $z) => [$z->name, $z->parent?->name])->filter()
            ->flatMap(fn ($n) => explode(' ', ZoneMatcher::normalize($n)))->unique();
        $kept = collect(preg_split('/[\s,;.\-:\/]+/u', $text) ?: [])
            ->reject(fn ($w) => $w === '' || $words->contains(fn ($z) => ZoneMatcher::close($w, $z)))
            ->reject(fn ($w) => in_array(ZoneMatcher::normalize($w), ['a', 'au', 'je', 'suis', 'moi', 'c', 'est', 'quartier', 'commune', 'nom', 'mon', 'livraison', 'livrer'], true));
        $name = trim($kept->take(4)->implode(' '));

        return $name === '' ? null : Str::title(mb_substr($name, 0, 120));
    }

    /** @return Collection<int, Zone> */
    private function companyZones(Merchant $merchant): Collection
    {
        return Zone::forCompany($merchant->company_id)->active()->with('parent')->get();
    }

    /** Réponse au client ; avec une photo : la photo et le texte en légende (connexion officielle) */
    /** @param  list<array{id: string, title: string, description?: string|null}>  $choices  boutons ou liste (connexion officielle) */
    private function reply(Merchant $merchant, string $to, string $text, ?string $image = null, array $choices = []): void
    {
        SendLiveWhatsApp::dispatch(self::session($merchant), $to, $text, $this->accountId, $this->accountId ? $image : null, $this->accountId ? $choices : []);
    }

    /** Connexion officielle : boutons et listes à toucher ; par code d'association : réponses à taper */
    private function buttons(): bool
    {
        return $this->accountId !== null;
    }

    /**
     * Suite du récapitulatif : liste à toucher (connexion officielle), sinon chiffres à taper
     *
     * @return array{0: string, 1: list<array{id: string, title: string}>}
     */
    private function menu(): array
    {
        if ($this->buttons()) {
            return ['👉 *Touchez « Choisir » pour répondre*', [
                ['id' => '1', 'title' => '✅ Je confirme'], ['id' => '2', 'title' => '➕ Ajouter un article'],
                ['id' => '3', 'title' => "📍 Changer l'adresse"], ['id' => '4', 'title' => '❌ Annuler'],
            ]];
        }

        return ["👉 *RÉPONDEZ AVEC UN CHIFFRE :*\n*1* ✅ Je confirme\n*2* ➕ Ajouter un article\n*3* 📍 Changer l'adresse\n*4* ❌ Annuler", []];
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' F';
    }
}
