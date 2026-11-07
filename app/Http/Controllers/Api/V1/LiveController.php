<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Live;
use App\Models\LiveChat;
use App\Models\LivePayment;
use App\Models\Merchant;
use App\Models\Product;
use App\Rules\PhoneNumber;
use App\Services\Live\LivePayments;
use App\Services\Live\LiveStudio;
use App\Services\Live\LiveWhatsApp;
use App\Services\Messaging\MessagingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Application e-commerçant : outil live. Préparer (produits, fiche produit par l'IA), télécharger la carte et
 * démarrer, enregistrer les commandes du direct, terminer et voir le bilan.
 */
class LiveController extends Controller
{
    // « Comment ça marche » montré en entier aux premières visites, replié ensuite
    public const INTRO_VISITS = 2;

    public function __construct(private readonly LiveStudio $studio, private readonly LiveWhatsApp $whatsapp) {}

    /** WhatsApp relié pour les commandes automatiques du live : état (et QR code / code pendant la liaison) */
    public function whatsapp(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->whatsapp->status($this->merchant($request))]);
    }

    /** Relier son WhatsApp : code d'association à saisir dans WhatsApp (numéro donné) et QR code */
    public function whatsappConnect(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:32', new PhoneNumber]], [], ['phone' => 'numéro']);
        try {
            return response()->json(['data' => $this->whatsapp->connect($merchant, $data['phone'] ?? null)]);
        } catch (MessagingException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
    }

    public function whatsappLogout(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        try {
            $this->whatsapp->disconnect($merchant);
        } catch (MessagingException $e) {
            throw new BusinessRuleException($e->getMessage());
        }

        return response()->json(['data' => $this->whatsapp->status($merchant->refresh())]);
    }

    /** Délier le numéro relié officiellement */
    public function whatsappOfficialLogout(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $this->whatsapp->disconnectOfficial($merchant);

        return response()->json(['data' => $this->whatsapp->state($merchant)]);
    }

    /** Offre, live en cours ou en préparation, derniers lives */
    public function index(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $current = Live::query()->where('merchant_id', $merchant->id)->whereIn('status', [Live::DRAFT, Live::LIVE])->latest('id')->first();

        return response()->json(['data' => [
            'offer' => $this->studio->offer($merchant),
            'current' => $current ? $this->studio->present($current) : null,
            'past' => Live::query()->where('merchant_id', $merchant->id)->where('status', Live::ENDED)->withCount(['items', 'orders'])
                ->latest('id')->limit(10)->get()->map(fn (Live $l) => [...$this->studio->present($l), 'orders_count' => $l->orders_count])->values(),
            // Chiffres des lives passés : l'article le plus commandé et la meilleure heure
            'insights' => $this->studio->insights($merchant),
        ]]);
    }

    /** « Comment ça marche » vu en entier : une visite de plus (replié après INTRO_VISITS visites) */
    public function introSeen(Request $request): JsonResponse
    {
        $this->merchant($request);
        $user = $request->user();
        $user->forceFill(['live_intro_views' => min(255, (int) $user->live_intro_views + 1)])->save();

        return response()->json(['data' => ['views' => $user->live_intro_views, 'full' => $user->live_intro_views < self::INTRO_VISITS]]);
    }

    /** Préparer mon live : live en préparation (créé au besoin) et catalogue */
    public function prepare(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $live = $this->studio->draft($merchant, $request->user());

        return response()->json(['data' => [
            ...$this->draftData($live, $merchant),
            'intro' => ['views' => (int) $request->user()->live_intro_views, 'full' => (int) $request->user()->live_intro_views < self::INTRO_VISITS],
        ]]);
    }

    public function items(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'items' => ['present', 'array', 'max:60'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant' => ['nullable', 'string', 'max:40'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $live = $this->studio->setItems($live, $data['items'], $data['name'] ?? null);

        return response()->json(['data' => $this->draftData($live, $merchant)]);
    }

    /** Onglet « Messages » : la vendeuse choisit le quartier d'un client pas reconnu, la commande part */
    public function completeChat(Request $request, Live $live, LiveChat $chat): JsonResponse
    {
        $merchant = $this->own($request, $live);
        abort_unless($chat->live_id === $live->id, 404);
        $data = $request->validate([
            'delivery_zone_id' => ['required', 'integer'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
        ], [], ['delivery_zone_id' => 'quartier', 'recipient_name' => 'nom']);
        $this->whatsapp->completeByMerchant($merchant, $chat, (int) $data['delivery_zone_id'], $data['recipient_name'] ?? null);

        return response()->json(['data' => [...$this->studio->board($live->refresh()), 'whatsapp' => $this->whatsappState($merchant), 'chats' => $this->whatsapp->chats($live)]]);
    }

    /** Étape « L'étiquette » faite : téléchargée, publiée et épinglée */
    public function labelReady(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);

        return response()->json(['data' => $this->draftData($this->studio->markLabelReady($live), $merchant)]);
    }

    /** Reprendre les produits d'un live passé dans le live en préparation */
    public function copy(Request $request, Live $live, Live $past): JsonResponse
    {
        $merchant = $this->own($request, $live);
        abort_unless($past->merchant_id === $merchant->id, 404);

        return response()->json(['data' => $this->draftData($this->studio->copyFrom($live, $past), $merchant)]);
    }

    /** Fiche produit par l'IA à partir d'une photo (comptée) */
    public function recognize(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);
        $request->validate([
            'photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:6144'],
            'token' => ['nullable', 'string', 'max:40'],
        ], [], ['photo' => 'photo']);

        return response()->json(['data' => $this->studio->recognize($live, $merchant, $request->file('photo'), $request->input('token'))]);
    }

    /** Photo d'un produit déjà dans le catalogue : la carte du live n'accepte que des produits en photo */
    public function productPhoto(Request $request, Product $product): JsonResponse
    {
        $merchant = $this->merchant($request);
        $request->validate(['photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:6144']], [], ['photo' => 'photo']);
        $product = $this->studio->setProductPhoto($merchant, $product, $request->file('photo'));

        return response()->json(['data' => ['product_id' => $product->id, 'photo_url' => $product->photoUrl()]]);
    }

    /** Nouveau produit (fiche IA vérifiée ou à la main) ajouté au catalogue et au live */
    public function addProduct(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:80'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'keep_in_catalog' => ['boolean'],
            'photo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:6144'],
        ], [], ['name' => 'nom', 'price' => 'prix', 'quantity' => 'quantité']);
        $product = $this->studio->createProduct($merchant, $request->user(), $data, $request->file('photo'));

        $rows = $live->items()->get()->map(fn ($i) => ['product_id' => $i->product_id, 'variant' => $i->variant, 'price' => $i->price, 'quantity' => $i->quantity])
            ->filter(fn ($r) => $r['product_id'] !== null)->values()->all();
        $rows[] = ['product_id' => $product->id, 'price' => $data['price'], 'quantity' => $data['quantity']];
        $live = $this->studio->setItems($live, $rows);

        return response()->json(['data' => $this->draftData($live, $merchant)], 201);
    }

    /** Télécharger la carte et ouvrir mon live : frais du live, puis direct */
    public function start(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);
        // Live payé à l'unité : paiement en ligne d'abord, le live démarre une fois payé
        if ($live->status === Live::DRAFT && $this->studio->paysOnline($merchant) && $this->studio->nextPlan($merchant) === 'single') {
            return $this->checkout($request, $merchant, 'live', $live);
        }
        $this->studio->start($live, $request->user());

        return response()->json(['data' => $this->studio->board($live->refresh())]);
    }

    public function board(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);

        return response()->json(['data' => [...$this->studio->board($live), 'whatsapp' => $this->whatsappState($merchant), 'chats' => $this->whatsapp->chats($live)]]);
    }

    public function order(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'max:32'],
            'delivery_zone_id' => ['required', 'integer', Rule::exists('zones', 'id')->where('company_id', $merchant->company_id)],
            'delivery_address' => ['nullable', 'string', 'max:500'],
        ], [], ['code' => 'code', 'recipient_name' => 'nom', 'recipient_phone' => 'numéro', 'delivery_zone_id' => 'quartier']);
        $this->studio->addOrder($live, $request->user(), $data);

        return response()->json(['data' => $this->studio->board($live->refresh())], 201);
    }

    public function end(Request $request, Live $live): JsonResponse
    {
        $this->own($request, $live);
        $this->studio->end($live);

        return response()->json(['data' => $this->studio->summary($live->refresh())]);
    }

    public function summary(Request $request, Live $live): JsonResponse
    {
        $this->own($request, $live);

        return response()->json(['data' => $this->studio->summary($live)]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        if ($this->studio->paysOnline($merchant)) {
            return $this->checkout($request, $merchant, 'monthly');
        }
        $merchant = $this->studio->subscribeMonthly($merchant, $request->user());

        return response()->json(['data' => $this->studio->offer($merchant)]);
    }

    public function pack(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        if ($this->studio->paysOnline($merchant)) {
            return $this->checkout($request, $merchant, 'pack');
        }
        $merchant = $this->studio->buyPack($merchant, $request->user());

        return response()->json(['data' => $this->studio->offer($merchant)]);
    }

    /** Retour de la page de paiement : état relu chez PayDunya (et appliqué s'il est payé) */
    public function payment(Request $request, LivePayment $payment, LivePayments $payments): JsonResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($payment->merchant_id === $merchant->id, 404);
        $payment = $payments->refresh($payment);

        return response()->json(['data' => [
            'id' => $payment->id,
            'kind' => $payment->kind,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'live_id' => $payment->live_id,
            'live_status' => $payment->live?->status,
            'error' => $payment->error,
        ]]);
    }

    /** Paiement à faire : adresse de la page de PayDunya */
    private function checkout(Request $request, Merchant $merchant, string $kind, ?Live $live = null): JsonResponse
    {
        $result = app(LivePayments::class)->checkout($merchant, $request->user(), $kind, $live, $request->getSchemeAndHttpHost());

        return response()->json(['data' => ['payment_url' => $result['url'], 'payment_id' => $result['payment']->id]]);
    }

    /** @return array<string, mixed> */
    private function draftData(Live $live, Merchant $merchant): array
    {
        $live->loadMissing('items.product');
        $catalog = Product::query()->where('merchant_id', $merchant->id)->where('is_active', true)->with('levels')
            ->orderBy('name')->limit(300)->get()
            ->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'price' => (int) $p->price, 'photo_url' => $p->photoUrl(),
                'stock' => $p->levels->isEmpty() ? null : (int) $p->levels->sum(fn ($l) => $l->available()),
            ])->values();

        return [
            'live' => $this->studio->present($live),
            'items' => $live->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'code' => $i->code, 'name' => $i->name, 'variant' => $i->variant, 'price' => $i->price,
                'quantity' => $i->quantity, 'photo_url' => $i->product?->photoUrl(),
            ])->values(),
            'catalog' => $catalog,
            'cards' => $this->studio->cardsLeft($live, $merchant),
            'offer' => $this->studio->offer($merchant),
            'shop_name' => $merchant->business_name,
            // Lien du live : sans WhatsApp relié, les clients commandent par ce lien
            'public_url' => $live->publicUrl(),
            'whatsapp' => $this->whatsappState($merchant),
            // Live en cours : la page ne montre que le bandeau « En direct » (commandes, montant)
            'totals' => $live->status === Live::LIVE ? $this->studio->board($live)['totals'] : null,
            // Étape « L'étiquette » faite pour les produits actuels
            'label_ready' => $this->studio->labelReady($live),
            // Lives passés à reprendre (produits, ordre, prix)
            'past' => Live::query()->where('merchant_id', $merchant->id)->where('status', Live::ENDED)->whereHas('items')
                ->with(['items' => fn ($q) => $q->orderBy('position')])->withCount('orders')->latest('id')->limit(8)->get()
                ->map(fn (Live $l) => [
                    'id' => $l->id, 'name' => $l->name, 'ended_at' => $l->ended_at, 'orders_count' => $l->orders_count,
                    'items' => $l->items->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'variant' => $i->variant, 'price' => $i->price])->values(),
                ])->values(),
        ];
    }

    /** Commandes automatiques (numéro officiel ou WhatsApp relié), sans appeler le service (lecture rapide) */
    private function whatsappState(Merchant $merchant): array
    {
        return $this->whatsapp->state($merchant);
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user();
        abort_unless($user->merchant_id !== null && $user->can('orders.create'), 403);

        return Merchant::with('company')->findOrFail($user->merchant_id);
    }

    private function own(Request $request, Live $live): Merchant
    {
        $merchant = $this->merchant($request);
        abort_unless($live->merchant_id === $merchant->id, 404);

        return $merchant;
    }
}
