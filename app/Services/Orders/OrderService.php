<?php

namespace App\Services\Orders;

use App\Enums\FeePayer;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Product;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Zone;
use App\Services\Assignment\AutoAssigner;
use App\Services\Platform\Billing;
use App\Services\Pricing\PricingService;
use App\Services\Stock\StockKeeper;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Création et modification des courses. Le prix est toujours calculé ici,
 * côté serveur, puis figé sur la course.
 */
class OrderService
{
    private const PICKUP_FIELDS = [
        'pickup_zone_id', 'pickup_address', 'pickup_landmark', 'pickup_contact_name', 'pickup_phone', 'pickup_lat', 'pickup_lng',
    ];

    private const DELIVERY_FIELDS = [
        'recipient_name', 'recipient_phone', 'recipient_phone2', 'delivery_zone_id', 'delivery_address',
        'delivery_landmark', 'delivery_lat', 'delivery_lng', 'delivery_scheduled_date', 'delivery_time_slot',
    ];

    private const PACKAGE_FIELDS = [
        'merchant_reference', 'description', 'package_size', 'weight_kg', 'is_fragile', 'is_express', 'merchant_note',
    ];

    private const MONEY_FIELDS = ['items_amount', 'fee_payer'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly OrderJournal $journal,
        private readonly StockKeeper $stock,
        private readonly PickupGroups $pickups,
        private readonly DeliveryDay $deliveryDay,
        private readonly AutoAssigner $autoAssigner,
    ) {}

    /**
     * @param  array<string, mixed>  $data  données validées (StoreOrderRequest)
     */
    public function create(User $actor, Merchant $merchant, array $data, string $source = 'dashboard'): Order
    {
        if (! $merchant->isActive()) {
            throw new BusinessRuleException('Ce compte marchand est suspendu.', 'merchant_id');
        }

        // Facture de la plateforme impayée au-delà du délai : plus de nouvelles courses
        if ($merchant->company && app(Billing::class)->isRestricted($merchant->company)) {
            throw new BusinessRuleException($actor->merchant_id
                ? 'La création de courses est momentanément suspendue. Contactez votre entreprise de livraison.'
                : 'Création de courses suspendue : une facture de votre abonnement est impayée. Voir « Mon abonnement ».');
        }

        return DB::transaction(function () use ($actor, $merchant, $data, $source) {
            $company = $merchant->company;
            $hub = $this->hub($merchant, $data['pickup_hub_id'] ?? null);
            $items = $this->items($merchant, $data['items'] ?? [], $hub);

            // Commande préparée à l'entrepôt : le livreur part de l'entrepôt
            if ($hub) {
                $data = [
                    ...$data,
                    'pickup_zone_id' => $hub->zone_id,
                    'pickup_address' => $hub->address,
                    'pickup_landmark' => $hub->landmark,
                    'pickup_contact_name' => $hub->name,
                    'pickup_phone' => $hub->phone,
                    'pickup_lat' => null,
                    'pickup_lng' => null,
                ];
            }

            // Montant et contenu déduits des articles quand ils ne sont pas indiqués
            if ($items !== []) {
                $data['items_amount'] ??= array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items));
                $data['description'] = filled($data['description'] ?? null)
                    ? $data['description']
                    : implode(', ', array_map(fn ($i) => "{$i['quantity']} × {$i['label']}", $items));
            }

            // Adresse de ramassage du marchand par défaut
            if ($hub === null) {
                $data['pickup_zone_id'] ??= $merchant->pickup_zone_id;
                $data['pickup_address'] ??= $merchant->pickup_address;
                $data['pickup_landmark'] ??= $merchant->pickup_landmark;
                $data['pickup_contact_name'] ??= $merchant->contact_name ?? $merchant->business_name;
                $data['pickup_phone'] ??= $merchant->phone;
                $data['pickup_lat'] ??= $merchant->pickup_lat;
                $data['pickup_lng'] ??= $merchant->pickup_lng;
            }

            if ($data['pickup_zone_id'] === null) {
                throw new BusinessRuleException('Indiquez la zone de ramassage.', 'pickup_zone_id');
            }

            $feePayer = FeePayer::tryFrom($data['fee_payer'] ?? '') ?? $merchant->default_fee_payer;
            $itemsAmount = (int) ($data['items_amount'] ?? 0);
            $pricing = $this->price($merchant, $data);

            $order = new Order([
                ...Arr::only($data, [...self::PICKUP_FIELDS, ...self::DELIVERY_FIELDS, ...self::PACKAGE_FIELDS]),
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'source' => $source,
                // L'application qui envoie la course prévient elle-même l'équipe du marchand
                'merchant_alerts_muted' => ($data['notify_merchant'] ?? true) === false,
                'created_by' => $actor->id,
                'pickup_hub_id' => $hub?->id,
                'fee_payer' => $feePayer,
                'items_amount' => $itemsAmount,
                'max_attempts' => $company->default_max_attempts,
                ...$pricing,
            ]);
            $order->cod_amount = Order::computeCodAmount($itemsAmount, $order->totalFees(), $feePayer);
            $order->recipient_id = $this->rememberRecipient($merchant, $data)->id;
            $order->save();

            $location = $items !== [] ? $this->stock->location($merchant, $hub) : null;
            foreach ($items as $item) {
                $orderItem = $order->items()->create([
                    'product_id' => $item['product']?->id,
                    'stock_location_id' => $item['product'] ? $location->id : null,
                    'label' => $item['label'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                if ($item['product']) {
                    $this->stock->reserve($order, $orderItem->setRelation('product', $item['product'])->setRelation('location', $location), $actor);
                }
            }

            $this->journal->record($order, $actor, OrderEventType::Created, [
                'to_status' => OrderStatus::Pending,
                'meta' => ['source' => $source],
            ]);

            // Règles de validation : journée fermée (heure limite ou dispatch) → livraison au prochain jour
            $postponed = $this->deliveryDay->postponeIfClosed($order, $company);

            if ($company->validatesAutomatically()) {
                $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()])->save();
                $this->journal->record($order, null, OrderEventType::StatusChanged, [
                    'from_status' => OrderStatus::Pending,
                    'to_status' => OrderStatus::Confirmed,
                    'note' => $postponed
                        ? "Validation automatique · livraison le {$order->delivery_scheduled_date->format('d/m')} ({$postponed})"
                        : 'Validation automatique',
                    'meta' => $postponed ? ['postponed' => $postponed] : null,
                ]);

                // Livreur déjà prévu chez ce client : le colis lui est ajouté ; sinon le planning des livreurs
                if ($this->pickups->attachToRunningPickup($order) || $this->autoAssigner->pickup($order)) {
                    $order = $order->fresh();
                }
            }

            return $order;
        });
    }

    /**
     * Modifie une course. Les champs modifiables dépendent de l'avancement :
     * ramassage avant récupération, livraison tant que le colis n'est pas en chemin.
     * Modification d'un e-commerçant depuis l'application : à valider par l'équipe selon le réglage de l'entreprise
     * (prix qui change, ou toujours) ; la course reste alors comme avant et une demande attend dans la file.
     *
     * @param  array<string, mixed>  $data  données validées (UpdateOrderRequest)
     * @param  array{notify_courier?: bool, approval?: bool}  $options  approval : appliquer la règle de validation
     */
    public function update(User $actor, Order $order, array $data, array $options = []): Order
    {
        return DB::transaction(function () use ($actor, $order, $data, $options) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            [$fees, $cod] = [$order->totalFees(), (int) $order->cod_amount];
            $changes = $this->prepare($order, $data);

            if ($changes === []) {
                return $order;
            }

            if (($options['approval'] ?? false) && $this->needsApproval($actor, $order, $changes, $fees)) {
                $this->requestChange($actor, $order->fresh(), $data, $changes, $fees, $cod, $order);

                return $order->fresh();
            }

            if ($order->isDirty(['recipient_phone', 'delivery_zone_id', 'delivery_address', 'recipient_name'])) {
                $order->recipient_id = $this->rememberRecipient($order->merchant, $order->only([
                    'recipient_name', 'recipient_phone', 'recipient_phone2', 'delivery_zone_id',
                    'delivery_address', 'delivery_landmark', 'delivery_lat', 'delivery_lng',
                ]))->id;
            }

            $order->save();

            $this->journal->record($order, $actor, OrderEventType::Edited, [
                'meta' => ['changes' => $changes, 'notify_courier' => $options['notify_courier'] ?? true],
            ]);

            return $order;
        });
    }

    /**
     * Aperçu avant d'enregistrer : changements lisibles, nouveau tarif et montant à encaisser, validation par
     * l'équipe nécessaire ou non. Rien n'est enregistré.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, Order $order, array $data): array
    {
        $copy = Order::query()->findOrFail($order->id);
        [$fees, $cod] = [$copy->totalFees(), (int) $copy->cod_amount];
        $changes = $this->prepare($copy, $data);

        return [
            'changes' => OrderChanges::describe($changes),
            'fees' => ['from' => $fees, 'to' => $copy->totalFees()],
            'cod' => ['from' => $cod, 'to' => (int) $copy->cod_amount],
            'needs_approval' => $changes !== [] && $this->needsApproval($actor, $copy, $changes, $fees),
        ];
    }

    /** Ce qui reste modifiable : tout (avant le ramassage), la livraison seulement, ou rien */
    public static function editScope(Order $order): ?string
    {
        $status = $order->status;

        return match (true) {
            $status->isFinal() || $status === OrderStatus::OutForDelivery => null,
            $status->isBeforePickup() => 'all',
            default => 'delivery',
        };
    }

    /**
     * Applique les champs sur la course (sans enregistrer) après les contrôles d'avancement, recalcule le prix et le
     * montant à encaisser.
     *
     * @return array<string, array{from: mixed, to: mixed}> changements
     */
    private function prepare(Order $order, array $data): array
    {
        $status = $order->status;

        if ($status->isFinal() || $status === OrderStatus::OutForDelivery) {
            throw new BusinessRuleException("Une course « {$status->label()} » ne peut plus être modifiée.", 'status');
        }

        if ($order->fromWarehouse() && array_intersect(array_keys($data), self::PICKUP_FIELDS)) {
            throw new BusinessRuleException('Cette commande part de l\'entrepôt : l\'adresse de ramassage ne se modifie pas.', 'pickup_zone_id');
        }

        if (! $status->isBeforePickup() && array_intersect(array_keys($data), [...self::PICKUP_FIELDS, ...self::MONEY_FIELDS, 'is_fragile', 'weight_kg', 'package_size'])) {
            throw new BusinessRuleException('Le colis a déjà été récupéré : seules les informations de livraison sont modifiables.', 'status');
        }

        $order->fill(Arr::only($data, [...self::PICKUP_FIELDS, ...self::DELIVERY_FIELDS, ...self::PACKAGE_FIELDS]));

        if (array_key_exists('fee_payer', $data)) {
            $order->fee_payer = FeePayer::from($data['fee_payer']);
        }

        if (array_key_exists('items_amount', $data)) {
            $order->items_amount = (int) $data['items_amount'];
        }

        if ($order->isDirty(['pickup_zone_id', 'delivery_zone_id', 'is_express', 'is_fragile', 'weight_kg'])) {
            $order->fill($this->price($order->merchant, $order->only([
                'pickup_zone_id', 'delivery_zone_id', 'is_express', 'is_fragile', 'weight_kg',
            ])));
        }

        $order->cod_amount = Order::computeCodAmount($order->items_amount, $order->totalFees(), $order->fee_payer);

        return collect($order->getDirty())->except(['updated_at', 'pricing_details'])
            ->map(fn ($new, $field) => ['from' => $order->getRawOriginal($field), 'to' => $order->getAttributes()[$field]])
            ->all();
    }

    /**
     * Validation par l'équipe : seulement pour un e-commerçant, selon le réglage (prix ou montant qui change, ou
     * toujours).
     */
    private function needsApproval(User $actor, Order $order, array $changes, int $feesBefore): bool
    {
        if ($actor->merchant_id === null) {
            return false;
        }

        return match ($order->company?->merchant_edit_approval ?? 'price') {
            'always' => true,
            'never' => false,
            default => $order->totalFees() !== $feesBefore || array_intersect(array_keys($changes), ['items_amount', 'fee_payer', 'cod_amount']) !== [],
        };
    }

    private function requestChange(User $actor, Order $order, array $data, array $changes, int $fees, int $cod, Order $changed): OrderChangeRequest
    {
        // Une seule demande en attente par course : la plus récente remplace la précédente
        OrderChangeRequest::query()->where('order_id', $order->id)->where('status', OrderChangeRequest::PENDING)
            ->update(['status' => OrderChangeRequest::REPLACED, 'decided_at' => now()]);

        $request = OrderChangeRequest::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'requested_by' => $actor->id,
            'status' => OrderChangeRequest::PENDING,
            'data' => $data,
            'changes' => OrderChanges::describe($changes),
            'fees_before' => $fees,
            'fees_after' => $changed->totalFees(),
            'cod_before' => $cod,
            'cod_after' => (int) $changed->cod_amount,
        ]);

        $price = $fees !== $changed->totalFees() ? ' · livraison '.Money::format($fees).' → '.Money::format($changed->totalFees()) : '';
        $this->journal->record($order, $actor, OrderEventType::Note, [
            'note' => 'Modification demandée, à valider : '.OrderChanges::labels($changes).$price,
            'meta' => ['change_request_id' => $request->id],
        ]);

        return $request;
    }

    /** Modification acceptée par l'équipe : appliquée telle que demandée (contrôles d'avancement refaits) */
    public function approveChange(User $actor, OrderChangeRequest $request): Order
    {
        return DB::transaction(function () use ($actor, $request) {
            $request = OrderChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== OrderChangeRequest::PENDING) {
                throw new BusinessRuleException('Cette modification a déjà été traitée.', 'status');
            }
            $order = $this->update($actor, $request->order, $request->data);
            $request->forceFill(['status' => OrderChangeRequest::APPROVED, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            $this->journal->record($order, $actor, OrderEventType::Note, [
                'note' => 'Modification acceptée par l\'agence.',
                'meta' => ['change_request_id' => $request->id],
            ]);

            return $order->fresh();
        });
    }

    public function refuseChange(User $actor, OrderChangeRequest $request, ?string $reason): Order
    {
        return DB::transaction(function () use ($actor, $request, $reason) {
            $request = OrderChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== OrderChangeRequest::PENDING) {
                throw new BusinessRuleException('Cette modification a déjà été traitée.', 'status');
            }
            $request->forceFill(['status' => OrderChangeRequest::REFUSED, 'decided_by' => $actor->id, 'decided_at' => now(), 'note' => $reason])->save();
            $this->journal->record($request->order, $actor, OrderEventType::Note, [
                'note' => 'Modification refusée par l\'agence'.($reason ? ' : '.$reason : '.').' La course reste comme avant.',
                'meta' => ['change_request_id' => $request->id],
            ]);

            return $request->order->fresh();
        });
    }

    public function addNote(User $actor, Order $order, string $note, bool $visibleToMerchant = true, ?float $lat = null, ?float $lng = null): void
    {
        $this->journal->record($order, $actor, OrderEventType::Note, [
            'note' => $note,
            'lat' => $lat,
            'lng' => $lng,
            // Une note du marchand est toujours visible par lui
            'visible_to_merchant' => $actor->merchant_id !== null || $visibleToMerchant,
        ]);
    }

    public function requestReturn(User $actor, Order $order, ?string $note = null): void
    {
        if ($order->status->isFinal() || $order->status->isBeforePickup()) {
            throw new BusinessRuleException('Le retour ne peut être demandé que pour un colis déjà récupéré. Avant le ramassage, annulez la course.', 'status');
        }

        DB::transaction(function () use ($actor, $order, $note) {
            $order->forceFill(['return_requested' => true])->save();
            $this->journal->record($order, $actor, OrderEventType::ReturnRequested, ['note' => $note]);
        });
    }

    private function hub(Merchant $merchant, ?int $hubId): ?Hub
    {
        if ($hubId === null) {
            return null;
        }

        return Hub::forCompany($merchant->company_id)->active()->find($hubId)
            ?? throw new BusinessRuleException('Entrepôt inconnu.', 'pickup_hub_id');
    }

    /**
     * Articles de la course : produits du stock du marchand ou articles libres.
     *
     * @param  list<array{product_id?: ?int, label?: ?string, quantity?: int, unit_price?: ?int}>  $rows
     * @return list<array{product: ?Product, label: string, quantity: int, unit_price: int}>
     */
    private function items(Merchant $merchant, array $rows, ?Hub $hub): array
    {
        $ids = array_filter(array_column($rows, 'product_id'));
        $products = Product::forCompany($merchant->company_id)
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->findMany($ids)
            ->keyBy('id');

        $items = [];
        foreach ($rows as $index => $row) {
            $product = null;
            if (! empty($row['product_id'])) {
                $product = $products->get($row['product_id'])
                    ?? throw new BusinessRuleException('Produit inconnu ou désactivé.', "items.{$index}.product_id");
            }

            $label = trim((string) ($row['label'] ?? '')) ?: $product?->name;
            if ($label === null || $label === '') {
                throw new BusinessRuleException('Indiquez le nom de l\'article.', "items.{$index}.label");
            }

            $items[] = [
                'product' => $product,
                'label' => mb_substr($label, 0, 255),
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'unit_price' => (int) ($row['unit_price'] ?? $product?->price ?? 0),
            ];
        }

        if ($hub && ! collect($items)->contains(fn ($i) => $i['product'] !== null)) {
            throw new BusinessRuleException('Choisissez les produits à prendre à l\'entrepôt.', 'items');
        }

        return $items;
    }

    /**
     * @return array{delivery_fee: int, surcharges_total: int, pricing_details: array}
     */
    private function price(Merchant $merchant, array $data): array
    {
        $pickupZone = Zone::forCompany($merchant->company_id)->find($data['pickup_zone_id']);
        $deliveryZone = Zone::forCompany($merchant->company_id)->find($data['delivery_zone_id']);

        if (! $pickupZone || ! $deliveryZone) {
            throw new BusinessRuleException('Zone inconnue.', $pickupZone ? 'delivery_zone_id' : 'pickup_zone_id');
        }

        $quote = $this->pricing->quote($merchant, $pickupZone, $deliveryZone, [
            'is_express' => (bool) ($data['is_express'] ?? false),
            'is_fragile' => (bool) ($data['is_fragile'] ?? false),
            'weight_kg' => isset($data['weight_kg']) ? (float) $data['weight_kg'] : null,
        ]);

        return [
            'delivery_fee' => $quote->basePrice,
            'surcharges_total' => $quote->surchargesTotal(),
            'pricing_details' => $quote->toArray(),
            'is_shipping' => $deliveryZone->is_shipping,
        ];
    }

    /**
     * Carnet de destinataires : crée ou met à jour la fiche du destinataire.
     */
    private function rememberRecipient(Merchant $merchant, array $data): Recipient
    {
        $recipient = Recipient::forCompany($merchant->company_id)->firstOrNew([
            'merchant_id' => $merchant->id,
            'phone' => PhoneNumber::normalize($data['recipient_phone']) ?? $data['recipient_phone'],
        ]);

        $recipient->fill(array_filter([
            'company_id' => $merchant->company_id,
            'name' => $data['recipient_name'] ?? null,
            'phone2' => $data['recipient_phone2'] ?? null,
            'zone_id' => $data['delivery_zone_id'] ?? null,
            'address' => $data['delivery_address'] ?? null,
            'landmark' => $data['delivery_landmark'] ?? null,
            'lat' => $data['delivery_lat'] ?? null,
            'lng' => $data['delivery_lng'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return $recipient;
    }
}
