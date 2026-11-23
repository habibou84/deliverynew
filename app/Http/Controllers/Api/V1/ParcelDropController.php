<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ParcelDrop;
use App\Models\ParcelGap;
use App\Services\Couriers\ParcelDrops;
use App\Services\Couriers\ParcelGaps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Colis rendus au dépôt sans agent (dépôt en libre-service) et écarts de remise.
 */
class ParcelDropController extends Controller
{
    public function __construct(
        private readonly ParcelDrops $drops,
        private readonly ParcelGaps $gaps,
    ) {}

    /** Borne du dépôt : QR code du moment (change toutes les 30 secondes) et dépôts du jour */
    public function kiosk(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $companyId = $request->user()->company_id;

        $enabled = ParcelDrops::enabled($companyId);

        return response()->json(['data' => [
            // Option désactivée : pas de QR code (les dépôts déjà faits restent à contrôler)
            'enabled' => $enabled,
            'code' => $enabled ? ParcelDrops::kioskCode($companyId) : null,
            'expires_in' => ParcelDrops::WINDOW - (time() % ParcelDrops::WINDOW),
            'today' => ParcelDrop::query()->whereDate('dropped_at', today())->count(),
            'pending' => ParcelDrop::query()->where('status', ParcelDrop::PENDING)->count(),
        ]]);
    }

    /** Dépôts à contrôler, et ceux des 7 derniers jours */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $drops = ParcelDrop::query()->with(['courier.user', 'controller'])
            ->where(fn ($q) => $q->where('status', ParcelDrop::PENDING)->orWhere('dropped_at', '>=', now()->subDays(7)))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('dropped_at')
            ->limit(100)->get();
        $orders = Order::query()->whereIn('id', $drops->pluck('order_ids')->flatten()->unique()->values())
            ->with('merchant:id,business_name')->orderBy('id')->get()->keyBy('id');

        return response()->json(['data' => $drops->map(fn (ParcelDrop $d) => ParcelDrops::data($d,
            collect($d->order_ids)->map(fn ($id) => $orders->get($id))->filter()->values()))->values()]);
    }

    public function photo(Request $request, ParcelDrop $parcelDrop): StreamedResponse
    {
        $this->authorizeStaff($request);
        abort_if($parcelDrop->photo_path === null || ! Storage::disk('local')->exists($parcelDrop->photo_path), 404);

        return Storage::disk('local')->response($parcelDrop->photo_path);
    }

    public function control(Request $request, ParcelDrop $parcelDrop): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'found_ids' => ['present', 'array'],
            'found_ids.*' => ['integer'],
            'missing' => ['nullable', 'array'],
            'missing.*.order_id' => ['required', 'integer'],
            'missing.*.reason' => ['required', Rule::in(array_keys(ParcelGap::REASONS))],
            'missing.*.note' => ['nullable', 'string', 'max:500'],
        ], [], ['missing' => 'colis introuvables']);

        $drop = $this->drops->control($request->user(), $parcelDrop, $data['found_ids'], self::missingMap($data['missing'] ?? []));

        return response()->json(['data' => ParcelDrops::data($drop->load(['courier.user', 'controller']))]);
    }

    /** Écarts de remise : contestations à trancher, puis ceux des 30 derniers jours */
    public function gaps(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $gaps = ParcelGap::query()->with(['order.merchant:id,business_name', 'courier.user', 'reporter', 'resolver'])
            ->where(fn ($q) => $q->whereNull('resolution')->orWhere('created_at', '>=', now()->subDays(30)))
            ->orderByRaw("CASE WHEN courier_answer = 'handed' AND resolution IS NULL THEN 0 WHEN resolution IS NULL THEN 1 ELSE 2 END")
            ->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $gaps->map(fn (ParcelGap $g) => $this->gaps->data($g))->values(),
            'can_resolve' => $this->canResolve($request),
        ]);
    }

    public function resolve(Request $request, ParcelGap $parcelGap): JsonResponse
    {
        abort_unless($this->canResolve($request), 403, 'Seul un administrateur tranche un écart de remise.');
        $data = $request->validate(['decision' => ['required', Rule::in([ParcelGap::AT_HUB, ParcelGap::COURIER])]]);

        $gap = $this->gaps->resolve($request->user(), $parcelGap, $data['decision']);

        return response()->json(['data' => $this->gaps->data($gap->load(['order.merchant', 'courier.user', 'reporter', 'resolver']))]);
    }

    /** Livreur : borne du dépôt scannée, il peut déposer ses colis (jeton valable 30 minutes) */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['kiosk_code' => ['required', 'string', 'max:120']]);

        return response()->json(['data' => ['session' => $this->drops->start($request->user(), $data['kiosk_code']), 'minutes' => ParcelDrops::SESSION_MINUTES]]);
    }

    /** Livreur : dépose ses colis dans le bac (borne scannée, colis scannés, photo) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => ['required', 'string', 'max:120'],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'typed_codes' => ['nullable', 'array'],
            'typed_codes.*' => ['string', 'max:40'],
            'photo' => ['required', 'image', 'max:8192'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [], ['photo' => 'photo du bac', 'order_ids' => 'colis']);

        $drop = $this->drops->drop($request->user(), $data['session'], $data['order_ids'], $data['typed_codes'] ?? [],
            $request->file('photo'), isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);

        return response()->json(['data' => ['id' => $drop->id, 'count' => count($drop->order_ids), 'dropped_at' => $drop->dropped_at]], 201);
    }

    /** Livreur : « je l'ai encore » ou « je l'ai remis » */
    public function answer(Request $request, ParcelGap $parcelGap): JsonResponse
    {
        $data = $request->validate(['answer' => ['required', Rule::in([ParcelGap::KEPT, ParcelGap::HANDED])]]);
        $gap = $this->gaps->answer($request->user(), $parcelGap, $data['answer']);

        return response()->json(['data' => ['id' => $gap->id, 'courier_answer' => $gap->courier_answer]]);
    }

    /**
     * Liste [{order_id, reason, note}] en tableau indexé par colis.
     *
     * @param  list<array{order_id: int, reason: string, note?: ?string}>  $missing
     * @return array<int, array{reason: string, note: ?string}>
     */
    public static function missingMap(array $missing): array
    {
        $map = [];
        foreach ($missing as $m) {
            $map[(int) $m['order_id']] = ['reason' => $m['reason'], 'note' => $m['note'] ?? null];
        }

        return $map;
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier() && (
            $user->can(Permission::FinanceManage->value)
            || $user->can(Permission::OrdersDispatch->value)
            || $user->can(Permission::OrdersUpdateStatus->value)
        ), 403);
    }

    private function canResolve(Request $request): bool
    {
        $user = $request->user();

        return $user->merchant_id === null && $user->can(Permission::OrdersDispatch->value) && $user->can(Permission::FinanceManage->value);
    }
}
