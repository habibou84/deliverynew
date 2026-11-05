<?php

namespace App\Services\Live;

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
use App\Services\WhatsApp\ZoneMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Commandes du live par WhatsApp, sans intervention de l'e-commerçante.
 *
 * Son numéro WhatsApp Business est relié à Jibiat par la connexion officielle de Meta (fenêtre « Connecter
 * WhatsApp », elle garde son application WhatsApp Business). Option de la console, désactivée par défaut : le
 * relier par code d'association ou QR code (session « merchant-34 » du service whatsapp-bridge, connexion non
 * officielle, risque de suspension du numéro). Pendant un live en cours, une cliente envoie le code d'un produit (« A2 », « A2 x2 », « JE PRENDS » quand
 * il n'y a qu'un article) ; Jibiat lui demande son nom et son quartier (ou propose ceux de sa dernière commande),
 * puis enregistre la commande : la course part à l'agence, ou la cliente passe en liste d'attente si c'est épuisé.
 * Les autres messages (conversations personnelles) sont ignorés.
 */
class LiveWhatsApp
{
    // Une conversation sans réponse est oubliée au bout de ce délai
    public const CHAT_MINUTES = 30;

    private const YES = ['oui', 'ok', 'okay', 'd accord', 'dac', 'yes', 'ouais', 'oui oui', 'oui merci', 'c est ca', 'exact', 'c est bon'];

    /** Numéro officiel de la conversation en cours (null : session du code d'association / QR code) */
    private ?int $accountId = null;

    public function __construct(
        private readonly WhatsAppBridge $bridge,
        private readonly LiveStudio $studio,
        private readonly ZoneMatcher $zones,
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

    // ── Conversation avec les clientes ────────────────────────────────────────────────────────────────

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

        // Un code seul relance toujours la commande (nouveau produit, ou changement d'avis)
        $pick = $this->pick($live, $text, strict: $chat !== null);
        if ($pick !== null) {
            $this->choose($merchant, $live, $phone, $pushName, $pick['item'], $pick['quantity']);

            return;
        }
        if ($chat === null) {
            // Message ordinaire (pas un code) : conversation personnelle, pas de réponse automatique
            return;
        }

        if ($chat->step === LiveChat::CONFIRM && in_array(ZoneMatcher::normalize($text), self::YES, true)) {
            $this->order($merchant, $live, $chat, $chat->customer_name, $chat->zone_id);

            return;
        }

        $zone = $this->zoneIn($merchant, $text);
        if ($zone === null) {
            $this->reply($merchant, $phone, "Je n'ai pas reconnu le quartier 🤔\nÉcrivez votre nom et votre quartier, par exemple :\n*Awa, Cocody Angré*");
            $chat->forceFill(['expires_at' => now()->addMinutes(self::CHAT_MINUTES)])->save();

            return;
        }
        $this->order($merchant, $live, $chat, $this->nameIn($text, $zone) ?? $chat->push_name ?? 'Cliente', $zone->id);
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

    private function choose(Merchant $merchant, Live $live, string $phone, ?string $pushName, LiveItem $item, int $quantity): void
    {
        $price = $this->money($item->price * $quantity);
        $line = ($quantity > 1 ? "{$quantity} × " : '')."*{$item->code}* {$item->label()} : {$price}";
        $soldOut = $item->remaining() < $quantity;

        // Cliente déjà livrée par cette boutique : on propose son nom et son quartier
        $last = Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('recipient_phone', $phone)
            ->whereNotNull('delivery_zone_id')->latest('id')->first(['recipient_name', 'delivery_zone_id']);
        $zone = $last ? Zone::withoutGlobalScopes()->find($last->delivery_zone_id) : null;

        LiveChat::query()->updateOrCreate(['live_id' => $live->id, 'phone' => $phone], [
            'push_name' => $pushName ? mb_substr($pushName, 0, 120) : null,
            'step' => $zone ? LiveChat::CONFIRM : LiveChat::DETAILS,
            'live_item_id' => $item->id,
            'quantity' => $quantity,
            'customer_name' => $zone ? $last->recipient_name : null,
            'zone_id' => $zone?->id,
            'expires_at' => now()->addMinutes(self::CHAT_MINUTES),
        ]);

        $intro = $soldOut
            ? "😔 {$line}\nC'est *épuisé* pour le moment. Je vous mets sur la liste d'attente :"
            : "👍 {$line}";
        $this->reply($merchant, $phone, $zone
            ? "{$intro}\nJe livre à *{$last->recipient_name}*, *{$zone->name}* ?\nRépondez *OUI*, ou envoyez votre nom et votre quartier."
            : "{$intro}\nPour la livraison, envoyez votre *nom* et votre *quartier*.\nExemple : *Awa, Yopougon Niangon*");
    }

    private function order(Merchant $merchant, Live $live, LiveChat $chat, string $name, int $zoneId): void
    {
        $item = $chat->item;
        if ($item === null) {
            $chat->delete();

            return;
        }
        $user = User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->orderBy('id')->first();
        if ($user === null) {
            return;
        }

        try {
            $order = $this->studio->addOrder($live, $user, [
                'code' => $item->code,
                'quantity' => $chat->quantity,
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
        if ($order->status === LiveOrder::WAITING) {
            $this->reply($merchant, $chat->phone, "📝 C'est noté, {$first} : vous êtes sur la *liste d'attente* pour {$item->code} {$item->label()}.\nOn vous écrit dès qu'il revient.");

            return;
        }
        $course = $order->order()->withoutGlobalScopes()->first(['id', 'cod_amount', 'delivery_zone_id']);
        $zone = Zone::withoutGlobalScopes()->find($zoneId);
        $this->reply($merchant, $chat->phone, "✅ C'est noté, {$first} !\n"
            .($chat->quantity > 1 ? "{$chat->quantity} × " : '')."{$item->code} {$item->label()}\n"
            ."Livraison : {$zone?->name}\n"
            .($course && $course->cod_amount ? "À payer à la livraison : *{$this->money($course->cod_amount)}*\n" : '')
            .'Le livreur vous appelle avant de passer. Merci ! 🙏');
    }

    private function zoneIn(Merchant $merchant, string $text): ?Zone
    {
        $zones = $this->companyZones($merchant);

        return $this->zones->inText($text, $zones)->first() ?? $this->zones->answer($text, $zones)->first();
    }

    /** Nom de la cliente : le message sans le quartier (« Awa, Yopougon Niangon » → « Awa ») */
    private function nameIn(string $text, Zone $zone): ?string
    {
        $words = collect([$zone->name, $zone->parent?->name])->filter()->flatMap(fn ($n) => explode(' ', ZoneMatcher::normalize($n)));
        $kept = collect(preg_split('/[\s,;.\-:\/]+/u', $text) ?: [])
            ->reject(fn ($w) => $w === '' || $words->contains(ZoneMatcher::normalize($w)))
            ->reject(fn ($w) => in_array(ZoneMatcher::normalize($w), ['a', 'au', 'je', 'suis', 'moi', 'c', 'est', 'quartier', 'commune', 'nom', 'mon', 'livraison', 'livrer'], true));
        $name = trim($kept->take(4)->implode(' '));

        return $name === '' ? null : Str::title(mb_substr($name, 0, 120));
    }

    /** @return Collection<int, Zone> */
    private function companyZones(Merchant $merchant): Collection
    {
        return Zone::forCompany($merchant->company_id)->active()->with('parent')->get();
    }

    private function reply(Merchant $merchant, string $to, string $text): void
    {
        SendLiveWhatsApp::dispatch(self::session($merchant), $to, $text, $this->accountId);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' F';
    }
}
