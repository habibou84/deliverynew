<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\ParcelRelay;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Relais entre livreurs : un colis encore chez un livreur (ramassage, échec, report) alors que sa livraison est
 * confiée à un autre. Celui qui a le colis affiche un code à 4 chiffres, celui qui le reçoit le saisit et coche les
 * colis reçus. Le dispatch peut valider à leur place (téléphone en panne). Sans relais, pas de départ en livraison
 * (réglage « Relais obligatoire »).
 */
class ParcelRelays
{
    // Le colis n'est plus à relayer : en route, livré, rendu, annulé…
    private const NOT_RELAYABLE = [
        OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Returning, OrderStatus::Returned,
        OrderStatus::Cancelled, OrderStatus::Lost, OrderStatus::Rejected,
    ];

    public function __construct(private readonly OrderJournal $journal) {}

    /** Le départ en livraison attend le relais : colis chez un autre livreur, réglage actif */
    public static function needed(Order $order, ?Company $company = null): bool
    {
        if ($order->held_by_courier_id === null || $order->delivery_courier_id === null
            || $order->held_by_courier_id === $order->delivery_courier_id) {
            return false;
        }

        $company = $company?->id === $order->company_id ? $company
            : ($order->relationLoaded('company') ? $order->company : Company::query()->find($order->company_id));

        return (bool) ($company?->relay_required ?? true);
    }

    /** Colis à relayer : chez un livreur, livraison confiée à un autre */
    public static function awaitingQuery(): Builder
    {
        return Order::query()
            ->whereNotNull('held_by_courier_id')
            ->whereNotNull('delivery_courier_id')
            ->whereColumn('held_by_courier_id', '!=', 'delivery_courier_id')
            ->whereNotIn('status', OrderStatus::values(self::NOT_RELAYABLE));
    }

    /**
     * Écran Relais du livreur : colis à remettre (par livreur qui les reçoit) et colis à recevoir (par livreur qui les a).
     *
     * @return array{give: list<array<string, mixed>>, receive: list<array<string, mixed>>}
     */
    public function board(Courier $courier): array
    {
        $orders = self::awaitingQuery()
            ->where(fn ($q) => $q->where('held_by_courier_id', $courier->id)->orWhere('delivery_courier_id', $courier->id))
            ->with(['holder.user', 'deliveryCourier.user', 'merchant:id,business_name', 'deliveryZone:id,name'])
            ->orderBy('held_since')->orderBy('id')
            ->get();

        $pending = ParcelRelay::query()->where('status', ParcelRelay::PENDING)
            ->where(fn ($q) => $q->where('from_courier_id', $courier->id)->orWhere('to_courier_id', $courier->id))
            ->where('expires_at', '>', now())
            ->get();

        $give = $orders->where('held_by_courier_id', $courier->id)->groupBy('delivery_courier_id')
            ->map(function (Collection $parcels) use ($pending, $courier) {
                $other = $parcels->first()->deliveryCourier;
                $relay = $pending->first(fn (ParcelRelay $r) => $r->from_courier_id === $courier->id && $r->to_courier_id === $other->id);

                return [
                    ...self::courierData($other),
                    'parcels' => $parcels->map(fn (Order $o) => self::parcelData($o))->values(),
                    'relay' => $relay ? [...self::relayData($relay), 'code' => $relay->code] : null,
                ];
            })->values();

        $receive = $orders->where('delivery_courier_id', $courier->id)->groupBy('held_by_courier_id')
            ->map(function (Collection $parcels) use ($pending, $courier) {
                $other = $parcels->first()->holder;
                $relay = $pending->first(fn (ParcelRelay $r) => $r->to_courier_id === $courier->id && $r->from_courier_id === $other->id);

                return [
                    ...self::courierData($other),
                    'parcels' => $parcels->map(fn (Order $o) => self::parcelData($o))->values(),
                    'relay' => $relay ? self::relayData($relay) : null,
                ];
            })->values();

        return ['give' => $give->all(), 'receive' => $receive->all()];
    }

    /** Nombre de colis à remettre ou à recevoir (badge de l'application) */
    public function count(Courier $courier): int
    {
        return self::awaitingQuery()
            ->where(fn ($q) => $q->where('held_by_courier_id', $courier->id)->orWhere('delivery_courier_id', $courier->id))
            ->count();
    }

    /**
     * Le livreur qui a les colis prépare le relais : code à 4 chiffres affiché chez lui, l'autre livreur prévenu.
     *
     * @param  list<int>  $orderIds
     */
    public function offer(Courier $from, Courier $to, array $orderIds, ?float $lat = null, ?float $lng = null): ParcelRelay
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $orders = self::awaitingQuery()->whereIn('id', $orderIds)
            ->where('held_by_courier_id', $from->id)->where('delivery_courier_id', $to->id)
            ->get();

        if ($orderIds === [] || $orders->count() !== count($orderIds)) {
            throw new BusinessRuleException('Certains colis ne sont pas (ou plus) à remettre à ce livreur.', 'order_ids');
        }

        $relay = DB::transaction(function () use ($from, $to, $orderIds, $lat, $lng) {
            // Un seul code valable à la fois entre ces deux livreurs
            ParcelRelay::query()->where('status', ParcelRelay::PENDING)
                ->where('from_courier_id', $from->id)->where('to_courier_id', $to->id)
                ->update(['status' => ParcelRelay::CANCELLED, 'updated_at' => now()]);

            return ParcelRelay::create([
                'company_id' => $from->company_id,
                'from_courier_id' => $from->id,
                'to_courier_id' => $to->id,
                'status' => ParcelRelay::PENDING,
                'code' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'order_ids' => $orderIds,
                'expires_at' => now()->addMinutes(ParcelRelay::TTL_MINUTES),
                'from_lat' => $lat,
                'from_lng' => $lng,
            ]);
        });

        $count = count($orderIds);
        $this->alert(collect([$to->user]), $orders->first(), 'relay',
            '🤝 Relais : '.($from->user?->name ?? 'un livreur').' a '.$count.' colis pour vous',
            'Demandez-lui le code à 4 chiffres et saisissez-le dans Relais.');

        return $relay;
    }

    /**
     * Le livreur qui reçoit saisit le code et coche les colis reçus : ils passent chez lui. Les colis non cochés
     * restent chez l'autre livreur, le dispatch est prévenu.
     *
     * @param  list<int>  $receivedIds
     */
    public function confirm(Courier $to, ParcelRelay $relay, string $code, array $receivedIds, ?float $lat = null, ?float $lng = null): ParcelRelay
    {
        if ($relay->to_courier_id !== $to->id) {
            throw new BusinessRuleException('Ce relais ne vous est pas destiné.', 'code');
        }

        if (! $relay->isOpen()) {
            throw new BusinessRuleException('Code expiré : demandez à l\'autre livreur d\'afficher un nouveau code.', 'code');
        }

        if (! hash_equals((string) $relay->code, trim($code))) {
            $relay->increment('attempts');
            $left = ParcelRelay::MAX_ATTEMPTS - $relay->attempts;

            throw new BusinessRuleException($left > 0
                ? "Code incorrect. Encore {$left} essai(s)."
                : 'Code incorrect trop de fois : demandez un nouveau code.', 'code');
        }

        $receivedIds = array_values(array_intersect($relay->order_ids, array_map('intval', $receivedIds)));

        if ($receivedIds === []) {
            throw new BusinessRuleException('Cochez les colis que vous avez reçus.', 'order_ids');
        }

        return $this->complete($relay, $receivedIds, actor: $to->user, lat: $lat, lng: $lng);
    }

    /**
     * Le dispatch valide le relais à la place des livreurs (téléphone en panne, pas de réseau), avec un motif.
     *
     * @param  list<int>  $orderIds
     */
    public function validateByDispatch(User $staff, Courier $from, Courier $to, array $orderIds, string $reason): ParcelRelay
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $count = self::awaitingQuery()->whereIn('id', $orderIds)
            ->where('held_by_courier_id', $from->id)->where('delivery_courier_id', $to->id)
            ->count();

        if ($orderIds === [] || $count !== count($orderIds)) {
            throw new BusinessRuleException('Certains colis ne sont pas (ou plus) à relayer entre ces livreurs.', 'order_ids');
        }

        $relay = DB::transaction(function () use ($staff, $from, $to, $orderIds, $reason) {
            ParcelRelay::query()->where('status', ParcelRelay::PENDING)
                ->where('from_courier_id', $from->id)->where('to_courier_id', $to->id)
                ->update(['status' => ParcelRelay::CANCELLED, 'updated_at' => now()]);

            return ParcelRelay::create([
                'company_id' => $from->company_id,
                'from_courier_id' => $from->id,
                'to_courier_id' => $to->id,
                'status' => ParcelRelay::PENDING,
                'code' => '',
                'order_ids' => $orderIds,
                'expires_at' => now(),
                'validated_by' => $staff->id,
                'reason' => mb_substr(trim($reason), 0, 255),
            ]);
        });

        return $this->complete($relay, $orderIds, actor: $staff);
    }

    /**
     * Relais du jour pour le dispatch : colis encore à relayer (par paire de livreurs) et relais faits aujourd'hui.
     *
     * @return array{awaiting: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function today(): array
    {
        $orders = self::awaitingQuery()
            ->with(['holder.user', 'deliveryCourier.user', 'merchant:id,business_name', 'deliveryZone:id,name'])
            ->orderBy('held_since')->orderBy('id')
            ->get();

        $pending = ParcelRelay::query()->where('status', ParcelRelay::PENDING)->where('expires_at', '>', now())->get();

        $awaiting = $orders->groupBy(fn (Order $o) => $o->held_by_courier_id.'-'.$o->delivery_courier_id)
            ->map(function (Collection $parcels) use ($pending) {
                $first = $parcels->first();
                $relay = $pending->first(fn (ParcelRelay $r) => $r->from_courier_id === $first->held_by_courier_id && $r->to_courier_id === $first->delivery_courier_id);

                return [
                    'from' => self::courierData($first->holder),
                    'to' => self::courierData($first->deliveryCourier),
                    'parcels' => $parcels->map(fn (Order $o) => self::parcelData($o))->values(),
                    'code_given_at' => $relay?->created_at,
                    'oldest' => $first->held_since,
                ];
            })->sortBy('oldest')->values();

        $done = ParcelRelay::query()->where('status', ParcelRelay::CONFIRMED)
            ->where('confirmed_at', '>=', today())
            ->with(['fromCourier.user', 'toCourier.user', 'validator:id,name'])
            ->latest('confirmed_at')->limit(100)->get()
            ->map(fn (ParcelRelay $r) => [
                ...self::relayData($r),
                'from' => self::courierData($r->fromCourier),
                'to' => self::courierData($r->toCourier),
                'received' => count($r->received_ids ?? []),
                'missing' => count($r->missing_ids ?? []),
                'validated_by' => $r->validator?->name,
                'reason' => $r->reason,
            ]);

        return ['awaiting' => $awaiting->all(), 'done' => $done->all()];
    }

    /** Nombre de colis encore à relayer (badge du dispatch) */
    public static function awaitingCount(): int
    {
        return self::awaitingQuery()->count();
    }

    /**
     * Les colis reçus passent chez le nouveau livreur ; les autres restent chez le premier.
     *
     * @param  list<int>  $receivedIds
     */
    private function complete(ParcelRelay $relay, array $receivedIds, ?User $actor, ?float $lat = null, ?float $lng = null): ParcelRelay
    {
        $relay->loadMissing(['fromCourier.user', 'toCourier.user']);
        $fromName = $relay->fromCourier->user?->name ?? 'livreur';
        $toName = $relay->toCourier->user?->name ?? 'livreur';
        $byDispatch = $relay->validated_by !== null;

        [$received, $missing] = DB::transaction(function () use ($relay, $receivedIds, $actor, $fromName, $toName, $byDispatch, $lat, $lng) {
            $relay = ParcelRelay::query()->whereKey($relay->id)->lockForUpdate()->first();

            if ($relay->status !== ParcelRelay::PENDING) {
                throw new BusinessRuleException('Ce relais est déjà fait.', 'code');
            }

            // Toujours chez le premier livreur et à livrer par le second (rien n'a changé entre-temps)
            $received = self::awaitingQuery()->whereIn('id', $receivedIds)
                ->where('held_by_courier_id', $relay->from_courier_id)->where('delivery_courier_id', $relay->to_courier_id)
                ->orderBy('id')->lockForUpdate()->get();
            $missing = array_values(array_diff($relay->order_ids, $received->modelKeys()));

            $note = "🤝 Relais : {$fromName} → {$toName}".($byDispatch ? ' (validé par le dispatch : '.$relay->reason.')' : '');
            foreach ($received as $order) {
                $order->handTo($relay->to_courier_id);
                // Mission ramassage gardée « jusqu'à la livraison » : terminée, le colis a changé de main
                $order->custody_plan = null;
                $order->save();

                $this->journal->record($order, $actor, OrderEventType::Relay, [
                    'note' => $note,
                    'visible_to_merchant' => false,
                    'meta' => ['relay_id' => $relay->id, 'from_courier_id' => $relay->from_courier_id, 'to_courier_id' => $relay->to_courier_id],
                ]);
            }

            $relay->update([
                'status' => ParcelRelay::CONFIRMED,
                'received_ids' => $received->modelKeys(),
                'missing_ids' => $missing,
                'confirmed_at' => now(),
                'to_lat' => $lat,
                'to_lng' => $lng,
            ]);

            return [$received, $missing];
        });

        // Colis attendus mais pas reçus : le dispatch et le premier livreur sont prévenus
        if ($missing !== []) {
            $orders = Order::query()->whereIn('id', $missing)->get(['id', 'company_id', 'tracking_code', 'recipient_name', 'status']);
            $codes = $orders->pluck('tracking_code')->implode(', ');
            $dispatch = User::forCompany($relay->company_id)->where('status', 'active')
                ->role([Role::Admin->value, Role::Dispatcher->value])->get();
            $this->alert($dispatch->push($relay->fromCourier->user)->filter(), $orders->first(), 'relay_missing',
                "⚠️ Relais incomplet : {$fromName} → {$toName}",
                count($missing)." colis non reçu(s) : {$codes}. Ils restent chez {$fromName}.");
        }

        if ($byDispatch && $received->isNotEmpty()) {
            $this->alert(collect([$relay->fromCourier->user, $relay->toCourier->user])->filter(), $received->first(), 'relay',
                '🤝 Relais validé par le dispatch', $received->count()." colis passent de {$fromName} à {$toName}.");
        }

        return $relay->fresh();
    }

    /** @param  Collection<int, User|null>  $users */
    private function alert(Collection $users, ?Order $order, string $kind, string $title, string $body): void
    {
        $users = $users->filter()->unique('id');

        if ($order && $users->isNotEmpty()) {
            Notification::send($users, new OrderAlert($order, $kind, $title, $body, ['url' => '/livreur/relais']));
        }
    }

    /** @return array<string, mixed> */
    private static function courierData(?Courier $courier): array
    {
        return [
            'courier_id' => $courier?->id,
            'name' => $courier?->user?->name,
            'phone' => $courier?->user?->phone,
            'lat' => $courier?->current_lat,
            'lng' => $courier?->current_lng,
            'located_at' => $courier?->last_location_at,
        ];
    }

    /** @return array<string, mixed> */
    private static function relayData(ParcelRelay $relay): array
    {
        return [
            'id' => $relay->id,
            'status' => $relay->status,
            'order_ids' => $relay->order_ids,
            'expires_at' => $relay->expires_at,
            'confirmed_at' => $relay->confirmed_at,
        ];
    }

    /** @return array<string, mixed> */
    private static function parcelData(Order $order): array
    {
        return [
            'id' => $order->id,
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'merchant' => $order->merchant?->business_name,
            'recipient_name' => $order->recipient_name,
            'zone' => $order->deliveryZone?->name,
            'cod_amount' => $order->cod_amount,
            'held_since' => $order->held_since,
        ];
    }
}
