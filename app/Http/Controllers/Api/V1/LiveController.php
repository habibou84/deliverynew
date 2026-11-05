<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Live;
use App\Models\Merchant;
use App\Models\Product;
use App\Rules\PhoneNumber;
use App\Services\Live\LiveStudio;
use App\Services\Live\LiveWhatsApp;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
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

    /** Offre, live en cours ou en préparation, derniers lives */
    public function index(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $current = Live::query()->where('merchant_id', $merchant->id)->whereIn('status', [Live::DRAFT, Live::LIVE])->latest('id')->first();

        return response()->json(['data' => [
            'offer' => $this->studio->offer($merchant),
            'current' => $current ? $this->studio->present($current) : null,
            'past' => Live::query()->where('merchant_id', $merchant->id)->where('status', Live::ENDED)->withCount('items')
                ->latest('id')->limit(10)->get()->map(fn (Live $l) => $this->studio->present($l))->values(),
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
        $this->own($request, $live);
        $this->studio->start($live, $request->user());

        return response()->json(['data' => $this->studio->board($live->refresh())]);
    }

    public function board(Request $request, Live $live): JsonResponse
    {
        $merchant = $this->own($request, $live);

        return response()->json(['data' => [...$this->studio->board($live), 'whatsapp' => $this->whatsappState($merchant)]]);
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
        $merchant = $this->studio->subscribeMonthly($this->merchant($request), $request->user());

        return response()->json(['data' => $this->studio->offer($merchant)]);
    }

    public function pack(Request $request): JsonResponse
    {
        $merchant = $this->studio->buyPack($this->merchant($request), $request->user());

        return response()->json(['data' => $this->studio->offer($merchant)]);
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
            'whatsapp' => $this->whatsappState($merchant),
        ];
    }

    /** État du WhatsApp relié, sans appeler le service (lecture rapide) */
    private function whatsappState(Merchant $merchant): array
    {
        return [
            'available' => WhatsAppBridge::configured(),
            'connected' => $merchant->live_wa_status === 'connected',
            'phone' => $merchant->live_wa_phone,
        ];
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
