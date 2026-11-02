<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Requests\V1\UpdateOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Merchants\QuickMerchant;
use App\Services\Orders\AwaitingCourier;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickupGroups;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public const LIST_RELATIONS = [
        'merchant', 'pickupZone', 'deliveryZone', 'lastIncidentReason', 'pickupHub', 'items',
        'pickupCourier.user', 'deliveryCourier.user', 'returnCourier.user', 'holder.user', 'merchantDecision',
        'pendingChange.requester',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            // File d'attente du dispatch : à valider, à ramasser, à livrer, à retourner
            'queue' => ['nullable', Rule::in(['to_confirm', 'late', 'to_pickup', 'to_prepare', 'to_deliver', 'unassigned', 'to_decide', 'scheduled', 'to_return', 'incidents', 'failed', 'code_to_verify', 'change_requests', 'untreated'])],
            // Courses non traitées : celles qui n'ont pas de livreur
            'without_courier' => ['nullable', 'boolean'],
            // Courses non traitées : livreur de la livraison, à défaut du ramassage
            'lead_courier_id' => ['nullable', 'integer'],
            // File « Sans livreur » : ramassage, livraison ou les deux
            'awaiting' => ['nullable', Rule::in([AwaitingCourier::PICKUP, AwaitingCourier::DELIVERY])],
            'merchant_id' => ['nullable', 'integer'],
            'hub_id' => ['nullable', 'integer'],
            'courier_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Période selon le jour de création (par défaut) ou le jour de livraison prévu
            'date_basis' => ['nullable', Rule::in(['created', 'delivery'])],
            // Jour de travail de l'agence : courses du jour et retards ; files de travail limitées à ce qui est dû
            'day' => ['nullable', 'date'],
            // Files de travail : ajouter les courses à livrer après ce jour (ramassage en avance)
            'include_later' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $queue = (string) $request->input('queue', '');
        // Une période choisie l'emporte sur le jour
        $day = $request->filled('day') && ! $request->filled('from') && ! $request->filled('to') ? $request->date('day')->toDateString() : null;

        $orders = Order::query()
            ->visibleTo($request->user())
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $request->input('status')))
            ->when($queue !== '' && $queue !== 'unassigned', fn ($q) => $this->applyQueue($q, $queue, $day))
            ->when($queue === 'unassigned', fn ($q) => AwaitingCourier::scope($q, $request->input('awaiting'), $day))
            ->when($day !== null, fn ($q) => $this->applyDay($q, $queue, $day, $request->boolean('include_later')))
            // En retard et non traitées : du plus ancien au plus récent
            ->when(in_array($queue, ['late', 'untreated'], true), fn ($q) => $q->orderByRaw(Order::DELIVERY_DAY_SQL))
            ->when($request->boolean('without_courier'), fn ($q) => $q->whereNull('pickup_courier_id')->whereNull('delivery_courier_id'))
            ->when($request->filled('lead_courier_id'), fn ($q) => $q->whereRaw('COALESCE(delivery_courier_id, pickup_courier_id) = ?', [$request->integer('lead_courier_id')]))
            // Sans livreur : de la plus ancienne attente à la plus récente
            ->when($queue === 'unassigned', fn ($q) => $q->orderBy('status_changed_at'))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('hub_id'), fn ($q) => $q->where('pickup_hub_id', $request->integer('hub_id')))
            ->when($request->filled('courier_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('pickup_courier_id', $request->integer('courier_id'))
                ->orWhere('delivery_courier_id', $request->integer('courier_id'))
                ->orWhere('return_courier_id', $request->integer('courier_id'))))
            ->when($request->filled('zone_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('delivery_zone_id', $request->integer('zone_id'))
                ->orWhere('pickup_zone_id', $request->integer('zone_id'))))
            ->forPeriod($request->date('from')?->toDateString(), $request->date('to')?->toDateString(), $request->input('date_basis', 'created'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = trim($request->string('search'));
                $term = '%'.mb_strtolower($raw).'%';
                $phone = PhoneNumber::normalize($raw);
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(tracking_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(merchant_reference) LIKE ?', [$term])
                    // Ancien numéro (courses reprises de l'ancienne application)
                    ->orWhere('legacy_id', $raw)
                    ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$term])
                    ->orWhere('recipient_phone', 'like', $phone ? "%{$phone}%" : $term));
            })
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 500));

        return OrderResource::collection($orders);
    }

    /**
     * Compteurs du dispatch : courses à valider, à ramasser, à livrer, et celles qui
     * attendent un livreur au-delà du délai de l'entreprise.
     */
    /**
     * Courses non traitées (jour de livraison dépassé, pas terminées), regroupées par jour prévu, par livreur
     * (celui de la livraison, à défaut du ramassage) ou par e-commerçant, avec le nombre et le montant à encaisser.
     */
    public function untreatedGroups(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $by = $request->validate(['by' => ['nullable', Rule::in(['date', 'courier', 'merchant'])]])['by'] ?? 'date';

        $base = Order::query()->untreated();
        $groups = match ($by) {
            'date' => $base->selectRaw(Order::DELIVERY_DAY_SQL.' AS day, COUNT(*) AS n, SUM(cod_amount) AS amount')
                ->groupByRaw(Order::DELIVERY_DAY_SQL)->orderByRaw(Order::DELIVERY_DAY_SQL)->get()
                ->map(fn ($r) => ['key' => (string) $r->day, 'label' => Carbon::parse($r->day)->locale('fr')->isoFormat('dddd D MMMM YYYY'), 'count' => (int) $r->n, 'amount' => (int) $r->amount]),
            'courier' => $base->selectRaw('COALESCE(delivery_courier_id, pickup_courier_id) AS courier, COUNT(*) AS n, SUM(cod_amount) AS amount, MIN('.Order::DELIVERY_DAY_SQL.') AS oldest')
                ->groupByRaw('COALESCE(delivery_courier_id, pickup_courier_id)')->orderByDesc('n')->get()
                ->map(fn ($r) => ['key' => $r->courier ? (string) $r->courier : 'none', 'label' => $r->courier ? (Courier::query()->with('user')->find($r->courier)?->user?->name ?? 'Livreur') : 'Sans livreur', 'count' => (int) $r->n, 'amount' => (int) $r->amount, 'oldest' => (string) $r->oldest]),
            'merchant' => $base->selectRaw('merchant_id, COUNT(*) AS n, SUM(cod_amount) AS amount, MIN('.Order::DELIVERY_DAY_SQL.') AS oldest')
                ->groupBy('merchant_id')->orderByDesc('n')->get()
                ->map(fn ($r) => ['key' => (string) $r->merchant_id, 'label' => Merchant::query()->find($r->merchant_id)?->business_name ?? 'E-commerçant', 'count' => (int) $r->n, 'amount' => (int) $r->amount, 'oldest' => (string) $r->oldest]),
        };

        return response()->json(['data' => ['by' => $by, 'total' => $groups->sum('count'), 'groups' => $groups->values()]]);
    }

    /**
     * Ramassages à assigner, regroupés par client et adresse : un lot s'assigne d'un coup.
     */
    public function pickupGroups(Request $request, PickupGroups $groups): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $day = $request->validate(['day' => ['nullable', 'date']])['day'] ?? null;

        return response()->json(['data' => $groups->toAssign($request->user(), $day ? Carbon::parse($day)->toDateString() : null)]);
    }

    /**
     * Étiquettes à imprimer en lot : les courses demandées que la personne a le droit de voir (les autres sont
     * ignorées), dans l'ordre demandé.
     */
    public function labels(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ])['ids'];

        $orders = Order::query()->visibleTo($request->user())->with(self::LIST_RELATIONS)->whereIn('id', $ids)->get()
            ->sortBy(fn (Order $o) => array_search($o->id, $ids))->values();

        return OrderResource::collection($orders);
    }

    public function counts(Request $request, AwaitingCourier $awaiting): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $count = fn (string $queue) => $this->applyQueue(Order::query(), $queue)->count();
        $day = $request->validate(['day' => ['nullable', 'date']])['day'] ?? null;
        // Courses en attente d'un livreur : lues une fois pour les trois compteurs
        $company = $request->user()->company;
        $waiting = $awaiting->waiting($company);

        return response()->json(['data' => [
            'to_confirm' => $count('to_confirm'),
            'to_pickup' => $count('to_pickup'),
            'to_deliver' => $count('to_deliver'),
            'unassigned' => AwaitingCourier::scope(Order::query())->count(),
            'change_requests' => $count('change_requests'),
            // Cas à régler (cartes au-dessus des onglets) : toutes dates, tant qu'ils ne sont pas réglés
            'exceptions' => collect(['late', 'untreated', 'to_return', 'to_decide', 'incidents', 'failed', 'code_to_verify', 'scheduled', 'to_prepare'])
                ->mapWithKeys(fn (string $queue) => [$queue => $count($queue)])->all(),
            'late' => $awaiting->lateCounts($company, $waiting),
            // Tableau de bord : en attente d'un livreur, par étape
            'waiting' => $awaiting->summary($company, $waiting),
            // Heure limite du jour : passée ou non, et courses encore sans livreur
            'cutoff' => $awaiting->cutoff($company, $waiting),
            // Liste de l'agence sur un jour : files limitées à ce qui est dû, courses prévues plus tard à part
            ...($day ? ['day' => $this->dayCounts(Carbon::parse($day)->toDateString())] : []),
        ]]);
    }

    /**
     * Compteurs d'un jour : ce qui est dû ce jour-là (retards compris) par file, ce qui est prévu plus tard,
     * et le nombre de courses d'aujourd'hui et de demain pour le sélecteur.
     *
     * @return array<string, mixed>
     */
    private function dayCounts(string $day): array
    {
        $queue = fn (string $name) => $name === 'unassigned' ? AwaitingCourier::scope(Order::query(), null, $day) : $this->applyQueue(Order::query(), $name, $day);
        $later = fn (string $name) => $queue($name)->whereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$day])->count();

        return [
            'date' => $day,
            'all' => Order::query()->ofDay($day)->count(),
            'to_confirm' => $queue('to_confirm')->ofDay($day)->count(),
            'late' => $queue('late')->count(),
            'to_return' => $queue('to_return')->ofDay($day)->count(),
            'failed' => $queue('failed')->ofDay($day)->count(),
            ...collect(self::WORK_QUEUES)->mapWithKeys(fn (string $name) => [$name => $queue($name)->ofDay($day)->count()])->all(),
            'later' => collect(self::LATER_QUEUES)->mapWithKeys(fn (string $name) => [$name => $later($name)])->all(),
            'days' => [
                'today' => Order::query()->ofDay(today()->toDateString())->count(),
                'tomorrow' => Order::query()->ofDay(today()->addDay()->toDateString())->count(),
            ],
        ];
    }

    /** Onglets limités aux courses du jour choisi (les jours passés sont dans « Courses non traitées ») */
    private const DAY_QUEUES = ['to_confirm', 'to_return', 'failed'];

    /** Files de travail : limitées au jour choisi (les retards sont dans « En retard ») */
    private const WORK_QUEUES = ['to_pickup', 'to_prepare', 'to_deliver', 'unassigned'];

    /** Files où l'on peut ajouter les courses prévues plus tard (ramassage ou préparation en avance) */
    private const LATER_QUEUES = ['to_pickup', 'to_prepare', 'unassigned'];

    /**
     * Jour choisi dans la liste : « Toutes » et les files de travail montrent les courses du jour (plus, sur demande, ce qui
     * est prévu plus tard), « En retard » celles d'avant encore en cours ; les autres files (à valider,
     * à décider, incidents, retours, codes) restent complètes : un problème reste visible jusqu'à sa résolution.
     */
    private function applyDay(Builder $query, string $queue, string $day, bool $includeLater): Builder
    {
        return match (true) {
            $queue === '' => $query->ofDay($day),
            in_array($queue, self::DAY_QUEUES, true) => $query->ofDay($day),
            in_array($queue, self::WORK_QUEUES, true) => $includeLater && in_array($queue, self::LATER_QUEUES, true)
                ? $query->whereRaw(Order::DELIVERY_DAY_SQL.' >= ?', [$day])
                : $query->ofDay($day),
            default => $query,
        };
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $source = $user->merchant_id !== null ? 'dashboard' : 'admin';

        // E-commerçant pas encore inscrit : créé avec sa première course (rien de créé si la course est refusée)
        $new = $data['new_merchant'] ?? null;
        unset($data['new_merchant']);
        if ($new !== null) {
            $quick = app(QuickMerchant::class);
            $sendAccess = (bool) ($new['send_access'] ?? true);
            $data['pickup_zone_id'] ??= $new['pickup_zone_id'] ?? null;
            [$merchant, $order] = DB::transaction(function () use ($quick, $user, $new, $data, $source, $sendAccess) {
                $merchant = $quick->create($user, $new);
                $order = $this->orders->create($user, $merchant, $data, $source);
                // Un seul message : la bienvenue remplace « course validée »
                if ($sendAccess) {
                    $quick->holdConfirmation($order);
                }

                return [$merchant, $order];
            });

            return OrderResource::make($this->loadDetail($order))
                ->additional(['meta' => ['new_merchant' => [
                    'id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                    'welcome' => $sendAccess ? $quick->welcome($merchant->fresh(), $order, $user) : null,
                ]]])
                ->response()->setStatusCode(201);
        }

        $merchant = Merchant::findOrFail($user->merchant_id ?? $data['merchant_id']);
        $order = $this->orders->create($user, $merchant, $data, $source);

        return OrderResource::make($this->loadDetail($order))->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return OrderResource::make($this->loadDetail($order));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $data = $request->validated();
        $notify = (bool) ($data['notify_courier'] ?? true);
        unset($data['notify_courier']);
        // E-commerçant : validation par l'équipe si le réglage de l'entreprise le demande
        $order = $this->orders->update($request->user(), $order, $data, ['approval' => true, 'notify_courier' => $notify]);

        return OrderResource::make($this->loadDetail($order));
    }

    /** Aperçu d'une modification : changements, nouveau tarif, montant à encaisser, validation nécessaire */
    public function editPreview(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();
        unset($data['notify_courier']);

        return response()->json(['data' => $this->orders->preview($request->user(), $order, $data)]);
    }

    public static function loadDetailRelations(Request $request): array
    {
        $user = $request->user();
        $isMerchant = $user?->merchant_id !== null;
        // Livreur : l'historique partagé avec l'e-commerçant et ses propres notes, pas les notes internes de l'équipe
        $isCourier = ! $isMerchant && $user?->isCourier() === true;

        return [
            ...self::LIST_RELATIONS,
            'events' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('visible_to_merchant', true))
                ->when($isCourier, fn ($q) => $q->where(fn ($q) => $q->where('visible_to_merchant', true)
                    ->orWhere(fn ($q) => $q->where('actor_type', 'user')->where('actor_id', $user->id))))
                ->with(['incidentReason', 'attachments'])
                ->orderBy('id'),
            'assignments' => fn ($q) => $q->with(['courier.user', 'assignedBy'])->orderBy('id'),
            // Le marchand ne voit que les frais qui lui sont facturés
            'expenses' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('billed_to', 'merchant'))
                ->with('courier.user')->orderBy('id'),
            'courierRatings',
        ];
    }

    private function loadDetail(Order $order): Order
    {
        return $order->fresh()->load(self::loadDetailRelations(request()));
    }

    private function applyQueue(Builder $query, string $queue, ?string $day = null): Builder
    {
        $day ??= today()->toDateString();

        return match ($queue) {
            'to_confirm' => $query->where('status', OrderStatus::Pending),
            // En retard : jour de livraison passé, course toujours en cours (hors retours demandés)
            'late' => $query->overdue($day),
            // Ramassages en cours : un livreur est assigné, le colis n'est pas encore récupéré
            // (les ramassages sans livreur sont dans « Sans livreur »)
            'to_pickup' => $query->whereIn('status', OrderStatus::values([OrderStatus::PickupAssigned, OrderStatus::PickupInProgress]))->whereNull('pickup_hub_id'),
            // Commandes d'entrepôt validées, à préparer avant la livraison
            'to_prepare' => $query->where('status', OrderStatus::Confirmed)->whereNotNull('pickup_hub_id'),
            // À livrer aujourd'hui : colis disponibles et reports arrivés à échéance
            // (colis ramassé pour une livraison un autre jour : pas encore à livrer)
            'to_deliver' => $query->whereIn('status', OrderStatus::values([OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::Rescheduled]))
                ->where(fn ($q) => $q->whereNull('delivery_scheduled_date')
                    ->orWhereDate('delivery_scheduled_date', '<=', $day))
                ->where('return_requested', false)
                ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Delivery->value)),
            // Échecs sans suite : relivrer, retourner ou remettre en stock
            'to_decide' => $query->needingDecision(),
            // Livraisons prévues un autre jour : reports, colis ramassés en avance et courses pas encore ramassées
            'scheduled' => $query->whereIn('status', OrderStatus::values([OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress, OrderStatus::Rescheduled, OrderStatus::PickedUp, OrderStatus::AtHub]))
                ->whereDate('delivery_scheduled_date', '>', $day)
                ->where('return_requested', false),
            'to_return' => $query->where('return_requested', true)
                ->whereNotIn('status', OrderStatus::values([OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Returned])),
            'incidents' => $query->whereIn('status', OrderStatus::values([OrderStatus::DeliveryFailed, OrderStatus::Rescheduled])),
            // Échec de livraison
            'failed' => $query->where('status', OrderStatus::DeliveryFailed),
            // Non traitées : jour de livraison dépassé et course pas terminée (retours demandés compris), toutes dates
            'untreated' => $query->untreated(),
            // Livrées hors connexion avec un code client incorrect : à vérifier auprès du destinataire
            'code_to_verify' => $query->whereNotNull('delivery_code_unverified_at'),
            // Modifications des e-commerçants à valider (prix qui change)
            'change_requests' => $query->whereHas('pendingChange'),
        };
    }
}
