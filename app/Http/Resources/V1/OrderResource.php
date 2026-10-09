<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCourier = $user?->isCourier() ?? false;

        return [
            'id' => $this->id,
            'tracking_code' => $this->tracking_code,
            'merchant_reference' => $this->merchant_reference,
            'source' => $this->source,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
                'phone' => $this->merchant->phone,
            ]),

            'pickup' => [
                'hub_id' => $this->pickup_hub_id,
                'hub_name' => $this->whenLoaded('pickupHub', fn () => $this->pickupHub?->name),
                'zone_id' => $this->pickup_zone_id,
                'zone_name' => $this->whenLoaded('pickupZone', fn () => $this->pickupZone->name),
                'address' => $this->pickup_address,
                'landmark' => $this->pickup_landmark,
                'contact_name' => $this->pickup_contact_name,
                'phone' => $this->pickup_phone,
                'lat' => $this->pickup_lat,
                'lng' => $this->pickup_lng,
            ],
            'recipient' => [
                'id' => $this->recipient_id,
                'name' => $this->recipient_name,
                'phone' => $this->recipient_phone,
                'phone2' => $this->recipient_phone2,
            ],
            'delivery' => [
                'zone_id' => $this->delivery_zone_id,
                'zone_name' => $this->whenLoaded('deliveryZone', fn () => $this->deliveryZone->name),
                'address' => $this->delivery_address,
                'landmark' => $this->delivery_landmark,
                'lat' => $this->delivery_lat,
                'lng' => $this->delivery_lng,
                'scheduled_date' => $this->delivery_scheduled_date?->toDateString(),
                'time_slot' => $this->delivery_time_slot,
            ],
            // Commande préparée à l'entrepôt : pas de ramassage
            'from_warehouse' => $this->fromWarehouse(),
            'prepared_at' => $this->prepared_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'label' => $item->label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'stock_state' => $item->stock_state,
            ])),
            // Zone d'expédition : colis déposé à une gare ou chez un transporteur
            'is_shipping' => $this->is_shipping,
            'shipping' => $this->when($this->is_shipping, fn () => [
                'fee' => $this->shipping_fee,
                'carrier' => $this->shipping_carrier,
                'reference' => $this->shipping_reference,
                'fee_estimate' => $this->relationLoaded('deliveryZone') ? $this->deliveryZone?->shipping_fee_estimate : null,
            ]),
            'package' => [
                'description' => $this->description,
                'size' => $this->package_size,
                'weight_kg' => $this->weight_kg,
                'is_fragile' => $this->is_fragile,
                'is_express' => $this->is_express,
                'merchant_note' => $this->merchant_note,
            ],
            'amounts' => [
                'delivery_fee' => $this->delivery_fee,
                'surcharges_total' => $this->surcharges_total,
                'total_fees' => $this->totalFees(),
                'surcharges' => $this->pricing_details['surcharges'] ?? [],
                'fee_payer' => $this->fee_payer,
                'items_amount' => $this->items_amount,
                'cod_amount' => $this->cod_amount,
                'collected_amount' => $this->collected_amount,
            ],
            // Le code de livraison est communiqué au destinataire par le marchand
            // (puis par WhatsApp en phase 3) ; jamais au livreur.
            'delivery_code' => $this->when(! $isCourier, fn () => $this->delivery_code),

            'pickup_courier' => $this->whenLoaded('pickupCourier', fn () => $this->courierSummary($this->pickupCourier)),
            'delivery_courier' => $this->whenLoaded('deliveryCourier', fn () => $this->courierSummary($this->deliveryCourier)),
            'return_courier' => $this->whenLoaded('returnCourier', fn () => $this->courierSummary($this->returnCourier)),
            // Garde du colis : visible du personnel uniquement
            'held_by' => $this->when($user?->merchant_id === null && $this->relationLoaded('holder'), fn () => $this->holder ? [
                ...$this->courierSummary($this->holder),
                'since' => $this->held_since,
            ] : null),
            'attempts_count' => $this->attempts_count,
            'max_attempts' => $this->max_attempts,
            'return_requested' => $this->return_requested,
            'last_incident' => $this->whenLoaded('lastIncidentReason', fn () => $this->lastIncidentReason ? [
                'id' => $this->lastIncidentReason->id,
                'label' => $this->lastIncidentReason->label,
            ] : null),
            'cancel_reason' => $this->cancel_reason,

            'events' => OrderEventResource::collection($this->whenLoaded('events')),
            'expenses' => OrderExpenseResource::collection($this->whenLoaded('expenses')),
            'assignments' => $this->when(
                $user?->merchant_id === null,
                fn () => OrderAssignmentResource::collection($this->whenLoaded('assignments')),
            ),

            'confirmed_at' => $this->confirmed_at,
            'picked_up_at' => $this->picked_up_at,
            'delivered_at' => $this->delivered_at,
            'returned_at' => $this->returned_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function courierSummary($courier): ?array
    {
        return $courier ? [
            'id' => $courier->id,
            'name' => $courier->user?->name,
            'phone' => $courier->user?->phone,
        ] : null;
    }
}
