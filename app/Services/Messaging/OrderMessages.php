<?php

namespace App\Services\Messaging;

use App\Enums\NotificationEvent;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Support\Money;

/**
 * Messages WhatsApp déclenchés par le journal d'une course : au marchand
 * (selon ses préférences) et au destinataire (colis en route, code de livraison).
 */
class OrderMessages
{
    public function __construct(private readonly Messenger $messenger) {}

    public function handle(Order $order, OrderEvent $event, ?User $actor): void
    {
        if (! in_array($event->type, [OrderEventType::StatusChanged, OrderEventType::Incident], true)) {
            return;
        }

        $order->loadMissing(['merchant', 'deliveryZone']);
        $merchant = $order->merchant;
        $code = $order->tracking_code;
        $recipient = $this->recipientLabel($order);
        $actorIsMerchant = $actor?->merchant_id !== null;

        switch ($event->to_status) {
            case OrderStatus::Confirmed:
                if ($event->from_status === OrderStatus::Pending) {
                    if (! $actorIsMerchant) {
                        $this->messenger->toMerchant($merchant, NotificationEvent::OrderConfirmed, WhatsAppTemplate::OrderConfirmed,
                            [$merchant->business_name, $code, $recipient, Money::format($order->totalFees())], $order);
                    }
                } else {
                    // Retour à « validée » depuis le ramassage : le ramassage a échoué
                    $this->incident($order, $event, $recipient, 'ramassage impossible');
                }
                break;

            case OrderStatus::PickedUp:
                $this->messenger->toMerchant($merchant, NotificationEvent::OrderPickedUp, WhatsAppTemplate::OrderPickedUp,
                    [$merchant->business_name, $code, $recipient], $order);
                break;

            case OrderStatus::OutForDelivery:
                // Expédition : le destinataire est prévenu au dépôt à la gare
                if ($order->is_shipping) {
                    break;
                }
                $this->messenger->toRecipient($order, 'order.out_for_delivery', WhatsAppTemplate::OutForDelivery, [
                    $order->recipient_name ?: 'cher client',
                    $merchant->business_name,
                    $order->cod_amount > 0 ? Money::format($order->cod_amount) : 'rien, déjà payé',
                    $order->delivery_code,
                    url('/suivi/'.$code),
                ]);
                break;

            case OrderStatus::Delivered:
                if ($order->is_shipping) {
                    $this->shipped($order, $recipient);
                    break;
                }
                $this->messenger->toMerchant($merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::OrderDelivered,
                    [$merchant->business_name, $code, $recipient, Money::format((int) $order->collected_amount)], $order);
                break;

            case OrderStatus::DeliveryFailed:
                $this->incident($order, $event, $recipient, 'livraison impossible');
                break;

            case OrderStatus::Rescheduled:
                if (! $actorIsMerchant) {
                    $date = $event->rescheduled_to?->format('d/m/Y');
                    $this->incident($order, $event, $recipient, 'livraison reportée'.($date ? " au {$date}" : ''));
                }
                break;

            default:
                break;
        }
    }

    private function shipped(Order $order, string $recipient): void
    {
        $merchant = $order->merchant;
        $ticket = $order->shipping_reference ?: 'sans numéro';

        $this->messenger->toMerchant($merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::Shipped, [
            $merchant->business_name, $order->tracking_code, $recipient, $order->shipping_carrier, $ticket, Money::format((int) $order->shipping_fee),
        ], $order);

        $this->messenger->toRecipient($order, 'order.shipped', WhatsAppTemplate::ShippedRecipient, [
            $order->recipient_name ?: 'cher client', $merchant->business_name, $order->shipping_carrier, $ticket,
        ]);
    }

    private function incident(Order $order, OrderEvent $event, string $recipient, string $what): void
    {
        $details = collect([$what, $event->incidentReason?->label, $event->note])->filter()->implode(', ');

        $this->messenger->toMerchant($order->merchant, NotificationEvent::OrderIncident, WhatsAppTemplate::DeliveryIncident, [
            $order->merchant->business_name,
            $order->tracking_code,
            $recipient,
            $details,
            url('/marchand/courses/'.$order->id),
        ], $order);
    }

    private function recipientLabel(Order $order): string
    {
        $name = $order->recipient_name ?: $order->recipient_phone;

        return $order->deliveryZone ? "{$name} ({$order->deliveryZone->name})" : (string) $name;
    }
}
