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
use App\Services\Messaging\Messenger;
use App\Services\Messaging\WhatsAppBridge;
use App\Services\WhatsApp\ZoneMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Commandes du live par WhatsApp, sans intervention de l'e-commerçante.
 *
 * Par défaut, les clientes écrivent au numéro WhatsApp officiel (API de Meta) : celui de l'agence s'il est relié,
 * sinon celui de Jibiat. Plusieurs boutiques peuvent être en live sur le même numéro : un code présent dans
 * plusieurs lives fait demander la boutique. Option de la console (désactivée par défaut, connexion non officielle,
 * risque de blocage) : relier le WhatsApp de l'e-commerçante (session « merchant-34 » du service whatsapp-bridge).
 *
 * Pendant un live en cours, une cliente envoie le code d'un produit (« A2 », « A2 x2 », « JE PRENDS » quand il n'y
 * a qu'un article) ; on lui demande son nom et son quartier (ou on propose ceux de sa dernière commande), puis la
 * commande est enregistrée : la course part à l'agence, ou la cliente passe en liste d'attente si c'est épuisé.
 * Les autres messages sont laissés à leur destination habituelle.
 */
class LiveWhatsApp
{
    // Une conversation sans réponse est oubliée au bout de ce délai
    public const CHAT_MINUTES = 30;

    private const YES = ['oui', 'ok', 'okay', 'd accord', 'dac', 'yes', 'ouais', 'oui oui', 'oui merci', 'c est ca', 'exact', 'c est bon'];

    // Choix de la boutique quand un code existe dans plusieurs lives du même numéro
    private const CHOICE_MINUTES = 10;

    /** Canal de la conversation en cours : ['own', session] ou ['official', id du numéro officiel] */
    private array $channel = [];

    public function __construct(
        private readonly WhatsAppBridge $bridge,
        private readonly LiveStudio $studio,
        private readonly ZoneMatcher $zones,
        private readonly Messenger $messenger,
    ) {}

    /** Option de la console : proposer aux e-commerçantes de relier leur propre WhatsApp (non officiel) */
    public function merchantOption(): bool
    {
        return (bool) $this->studio->settings()['merchant_whatsapp'];
    }

    /** Commandes reçues sur le WhatsApp de l'e-commerçante (option active et WhatsApp relié) */
    public function usesOwn(Merchant $merchant): bool
    {
        return $merchant->live_wa_status === 'connected' && $this->merchantOption();
    }

    /** Numéro officiel du live : celui de l'agence (API de Meta), sinon celui de Jibiat */
    public function officialAccount(?int $companyId): ?WhatsAppAccount
    {
        $own = $companyId ? WhatsAppAccount::forCompanyId($companyId) : null;
        if ($own !== null && ! $own->isQr() && $own->isLive()) {
            return $own;
        }
        $platform = WhatsAppAccount::platform();

        return $platform !== null && ! $platform->isQr() && $platform->isLive() ? $platform : null;
    }

    /** Numéro affiché sur la carte du live : celui qui reçoit les commandes automatiques, sinon celui de la boutique */
    public function cardPhone(Merchant $merchant): ?string
    {
        if ($this->usesOwn($merchant) && $merchant->live_wa_phone) {
            return $merchant->live_wa_phone;
        }
        $official = $this->officialAccount($merchant->company_id);

        return $official ? (PhoneNumber::normalize($official->display_phone) ?? $official->display_phone) : $merchant->messagingPhone();
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
        if (! $this->merchantOption()) {
            throw new MessagingException('Les commandes du live passent par le numéro WhatsApp officiel.');
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
        if ($this->merchantOption() && WhatsAppBridge::configured() && $merchant->live_wa_status !== null) {
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
     * Commandes automatiques du live : numéro officiel (par défaut) ou WhatsApp de l'e-commerçante (option).
     *
     * @return array<string, mixed>
     */
    public function state(Merchant $merchant, ?array $bridge = null): array
    {
        $own = $this->usesOwn($merchant);
        $official = $this->officialAccount($merchant->company_id);

        return [
            'mode' => $own ? 'merchant' : ($official ? 'official' : 'none'),
            'auto' => $own || $official !== null,
            'official' => $official ? [
                'phone' => PhoneNumber::normalize($official->display_phone) ?? $official->display_phone,
                'name' => $official->isPlatform() ? 'Jibiat' : ($merchant->company?->name ?? $official->verified_name),
            ] : null,
            'merchant_option' => $this->merchantOption(),
            'available' => $this->merchantOption() && WhatsAppBridge::configured(),
            'connected' => $own,
            'phone' => $merchant->live_wa_phone,
            'connected_at' => $merchant->live_wa_connected_at,
            'qr' => $bridge['qr'] ?? null,
            'pairing_code' => $bridge['pairing_code'] ?? null,
        ];
    }

    // ── Conversation avec les clientes ────────────────────────────────────────────────────────────────

    /**
     * Message reçu sur le WhatsApp de l'e-commerçante (option de la console).
     */
    public function onMessage(int $merchantId, string $from, ?string $pushName, ?string $text): void
    {
        $merchant = Merchant::withoutGlobalScopes()->with('company')->find($merchantId);
        $text = trim((string) $text);
        if ($merchant === null || $text === '' || ! $this->merchantOption()) {
            return;
        }
        $live = Live::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('status', Live::LIVE)->latest('id')->first();
        if ($live === null) {
            return;
        }
        $this->channel = ['own', self::session($merchant)];
        $this->converse($merchant, $live, PhoneNumber::normalize($from) ?? $from, $pushName, $text);
    }

    /**
     * Message reçu sur un numéro officiel (agence ou Jibiat). Vrai si c'est une commande du live (traitée ici),
     * faux sinon : le message suit alors son chemin habituel (création de course, messagerie de la console…).
     */
    public function onOfficial(WhatsAppAccount $account, string $from, ?string $pushName, ?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        $lives = $this->livesOn($account);
        if ($lives->isEmpty()) {
            return false;
        }
        $phone = PhoneNumber::normalize($from) ?? $from;
        $this->channel = ['official', $account->id];

        // Réponse à « quelle boutique ? » : le chiffre choisi, puis le message d'origine dans ce live
        $key = "live-wa-choice:{$account->id}:{$phone}";
        $choice = Cache::get($key);
        if ($choice !== null && preg_match('/^\s*(\d)\s*[.)]?\s*$/', $text, $m)) {
            $live = $lives->firstWhere('id', $choice['lives'][(int) $m[1] - 1] ?? 0);
            if ($live !== null) {
                Cache::forget($key);
                $this->converse($live->merchant, $live, $phone, $pushName, $choice['text']);

                return true;
            }
        }

        // Conversation en cours (nom et quartier attendus)
        $chat = LiveChat::query()->whereIn('live_id', $lives->pluck('id'))->where('phone', $phone)
            ->where('expires_at', '>', now())->latest('updated_at')->first();
        if ($chat !== null) {
            $live = $lives->firstWhere('id', $chat->live_id);
            $this->converse($live->merchant, $live, $phone, $pushName, $text);

            return true;
        }

        $matches = $lives->filter(fn (Live $live) => $this->pick($live, $text, strict: false) !== null)->values();
        if ($matches->isEmpty()) {
            return false;
        }
        if ($matches->count() === 1) {
            $this->converse($matches[0]->merchant, $matches[0], $phone, $pushName, $text);

            return true;
        }

        // Le même code dans plusieurs lives : la cliente choisit la boutique
        Cache::put($key, ['lives' => $matches->pluck('id')->all(), 'text' => $text], now()->addMinutes(self::CHOICE_MINUTES));
        $list = $matches->values()->map(fn (Live $live, int $i) => ($i + 1).'. '.$live->merchant->business_name)->implode("\n");
        $this->reply($matches[0]->merchant, $phone, "Plusieurs boutiques sont en live en ce moment 🛍️\nLaquelle regardez-vous ?\n{$list}\n\nRépondez par le *chiffre*.");

        return true;
    }

    /**
     * Lives en cours dont les commandes arrivent sur ce numéro officiel.
     *
     * @return Collection<int, Live>
     */
    private function livesOn(WhatsAppAccount $account): Collection
    {
        $lives = Live::withoutGlobalScopes()->where('status', Live::LIVE)->get();
        $merchants = Merchant::withoutGlobalScopes()->with('company')->whereIn('id', $lives->pluck('merchant_id'))->get()->keyBy('id');

        return $lives->each(fn (Live $live) => $live->setRelation('merchant', $merchants->get($live->merchant_id)))
            ->filter(fn (Live $live) => $live->merchant !== null && ! $this->usesOwn($live->merchant)
                && $this->officialAccount($live->merchant->company_id)?->id === $account->id)
            ->values();
    }

    private function converse(Merchant $merchant, Live $live, string $phone, ?string $pushName, string $text): void
    {
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
        // Numéro partagé par plusieurs boutiques : on rappelle laquelle
        if (($this->channel[0] ?? null) === 'official') {
            $intro = "🛍️ *{$merchant->business_name}*\n{$intro}";
        }
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
        [$kind, $target] = $this->channel + [null, null];
        if ($kind !== 'official') {
            SendLiveWhatsApp::dispatch(self::session($merchant), $to, $text);

            return;
        }
        $account = WhatsAppAccount::withoutGlobalScopes()->find($target);
        if ($account?->isPlatform()) {
            // Numéro de Jibiat : réponse libre, la cliente vient d'écrire (fenêtre de 24 heures de Meta)
            SendLiveWhatsApp::dispatch(SendLiveWhatsApp::PLATFORM, $to, $text);

            return;
        }
        $this->messenger->reply($account->company_id, $to, $text, [], ['recipient_type' => 'recipient', 'merchant_id' => $merchant->id]);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' F';
    }
}
