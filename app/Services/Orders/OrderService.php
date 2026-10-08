<?php

namespace App\Services\Orders;

use App\Enums\FeePayer;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Zone;
use App\Services\Pricing\PricingService;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data  données validées (StoreOrderRequest)
     */
    public function create(User $actor, Merchant $merchant, array $data, string $source = 'dashboard'): Order
    {
        if (! $merchant->isActive()) {
            throw new BusinessRuleException('Ce compte marchand est suspendu.', 'merchant_id');
        }

        return DB::transaction(function () use ($actor, $merchant, $data, $source) {
            $company = $merchant->company;

            // Adresse de ramassage du marchand par défaut
            $data['pickup_zone_id'] ??= $merchant->pickup_zone_id;
            $data['pickup_address'] ??= $merchant->pickup_address;
            $data['pickup_landmark'] ??= $merchant->pickup_landmark;
            $data['pickup_contact_name'] ??= $merchant->contact_name ?? $merchant->business_name;
            $data['pickup_phone'] ??= $merchant->phone;
            $data['pickup_lat'] ??= $merchant->pickup_lat;
            $data['pickup_lng'] ??= $merchant->pickup_lng;

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
                'created_by' => $actor->id,
                'fee_payer' => $feePayer,
                'items_amount' => $itemsAmount,
                'max_attempts' => $company->default_max_attempts,
                ...$pricing,
            ]);
            $order->cod_amount = Order::computeCodAmount($itemsAmount, $order->totalFees(), $feePayer);
            $order->recipient_id = $this->rememberRecipient($merchant, $data)->id;
            $order->save();

            $this->journal->record($order, $actor, OrderEventType::Created, [
                'to_status' => OrderStatus::Pending,
                'meta' => ['source' => $source],
            ]);

            if ($company->auto_confirm_orders) {
                $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()])->save();
                $this->journal->record($order, null, OrderEventType::StatusChanged, [
                    'from_status' => OrderStatus::Pending,
                    'to_status' => OrderStatus::Confirmed,
                    'note' => 'Validation automatique',
                ]);
            }

            return $order;
        });
    }

    /**
     * Modifie une course. Les champs modifiables dépendent de l'avancement :
     * ramassage avant récupération, livraison tant que le colis n'est pas en chemin.
     *
     * @param  array<string, mixed>  $data  données validées (UpdateOrderRequest)
     */
    public function update(User $actor, Order $order, array $data): Order
    {
        return DB::transaction(function () use ($actor, $order, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $status = $order->status;

            if ($status->isFinal() || $status === OrderStatus::OutForDelivery) {
                throw new BusinessRuleException("Une course « {$status->label()} » ne peut plus être modifiée.", 'status');
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

            $changes = collect($order->getDirty())->except(['updated_at', 'pricing_details'])
                ->map(fn ($new, $field) => ['from' => $order->getRawOriginal($field), 'to' => $order->getAttributes()[$field]])
                ->all();

            if ($changes === []) {
                return $order;
            }

            if ($order->isDirty(['recipient_phone', 'delivery_zone_id', 'delivery_address', 'recipient_name'])) {
                $order->recipient_id = $this->rememberRecipient($order->merchant, $order->only([
                    'recipient_name', 'recipient_phone', 'recipient_phone2', 'delivery_zone_id',
                    'delivery_address', 'delivery_landmark', 'delivery_lat', 'delivery_lng',
                ]))->id;
            }

            $order->save();

            $this->journal->record($order, $actor, OrderEventType::Edited, [
                'meta' => ['changes' => $changes],
            ]);

            return $order;
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
