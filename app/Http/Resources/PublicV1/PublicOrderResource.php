<?php

namespace App\Http\Resources\PublicV1;

use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Course telle que l'expose l'API publique : contrat stable, documenté dans
 * public/docs/openapi.yaml (indépendant des écrans internes).
 *
 * @mixin Order
 */
class PublicOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tracking_code' => $this->tracking_code,
            'merchant_reference' => $this->merchant_reference,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),
            'tracking_url' => url('/suivi/'.$this->tracking_code),
            'delivery_code' => $this->delivery_code,
            'recipient' => [
                'name' => $this->recipient_name,
                'phone' => $this->recipient_phone,
                'phone2' => $this->recipient_phone2,
            ],
            'delivery' => [
                'zone_id' => $this->delivery_zone_id,
                'zone_name' => $this->whenLoaded('deliveryZone', fn () => $this->deliveryZone?->name),
                'address' => $this->delivery_address,
                'landmark' => $this->delivery_landmark,
                'scheduled_date' => $this->delivery_scheduled_date?->toDateString(),
            ],
            'from_warehouse' => $this->fromWarehouse(),
            'description' => $this->description,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'label' => $item->label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])),
            'amounts' => [
                'items_amount' => $this->items_amount,
                'delivery_fee' => $this->totalFees(),
                'fee_payer' => $this->fee_payer->value,
                'cod_amount' => $this->cod_amount,
                'collected_amount' => $this->collected_amount,
                'shipping_fee' => $this->is_shipping ? $this->shipping_fee : null,
            ],
            'attempts_count' => $this->attempts_count,
            'last_incident' => $this->whenLoaded('lastIncidentReason', fn () => $this->lastIncidentReason?->label),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (OrderEvent $e) => [
                'type' => $e->type->value,
                'status' => $e->to_status?->value,
                'note' => $e->note,
                'incident' => $e->incidentReason?->label,
                'rescheduled_to' => $e->rescheduled_to?->toDateString(),
                'at' => $e->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'delivered_at' => $this->delivered_at,
            'cancelled_at' => $this->cancelled_at,
            'returned_at' => $this->returned_at,
        ];
    }
}
