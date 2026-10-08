<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;

/**
 * Traduit les étapes d'une course en écritures financières (appelé par
 * OrderWorkflow dans la même transaction) :
 * - livrée : encaissement, crédit du marchand, frais retenus, gain du livreur ;
 * - expédiée (zone d'expédition) : en plus, frais d'expédition facturés au marchand
 *   et remboursés au livreur sur son versement ;
 * - récupérée : gain du ramasseur ;
 * - retournée : frais de retour facturés au marchand, gain du livreur.
 */
class FinanceRecorder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function onTransition(Order $order, OrderStatus $to, ?OrderAssignment $assignment, array $context, User $actor): void
    {
        $courier = $assignment?->courier;

        match ($to) {
            OrderStatus::PickedUp => $this->earn($courier, EarningType::Pickup, $courier?->pickup_commission, $order),
            OrderStatus::Delivered => $this->delivered($order, $courier, $context, $actor),
            OrderStatus::Returned => $this->returned($order, $courier, $actor),
            default => null,
        };
    }

    private function delivered(Order $order, ?Courier $courier, array $context, User $actor): void
    {
        $collected = (int) $order->collected_amount;
        // Frais d'expédition avancés par le livreur : déduits de ce qu'il verse à la caisse
        $expense = $order->is_shipping && $courier !== null ? (int) $order->shipping_fee : 0;

        if ($collected > 0 || $expense > 0) {
            $method = PaymentMethod::tryFrom($context['payment_method'] ?? '') ?? PaymentMethod::Cash;

            CashCollection::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'courier_id' => $courier?->id,
                'amount_expected' => $order->cod_amount,
                'amount_collected' => $collected,
                'courier_expense' => $expense,
                'method' => $method,
                'received_by_company' => $method !== PaymentMethod::Cash && (bool) ($context['received_by_company'] ?? false),
                'transaction_ref' => $context['transaction_ref'] ?? null,
                'collected_at' => now(),
            ]);

            if ($collected > 0) {
                $this->ledger($order, LedgerEntryType::CodCredit, $collected, $actor, 'Encaissé à la livraison');
            }
        }

        // Frais retenus quel que soit le payeur : payés par le client, ils sont inclus dans l'encaissement
        $this->ledger($order, LedgerEntryType::DeliveryFee, -$order->totalFees(), $actor, 'Frais de livraison');

        if ($order->is_shipping) {
            $details = collect([$order->shipping_carrier, $order->shipping_reference ? 'ticket '.$order->shipping_reference : null])->filter()->implode(', ');
            $this->ledger($order, LedgerEntryType::ShippingFee, -(int) $order->shipping_fee, $actor, "Frais d'expédition ({$details})");
        }

        $this->earn($courier, EarningType::Delivery, $courier?->delivery_commission, $order);
    }

    private function returned(Order $order, ?Courier $courier, User $actor): void
    {
        $percent = $order->company->return_fee_percent;
        $fee = (int) round($order->totalFees() * $percent / 100);

        $this->ledger($order, LedgerEntryType::ReturnFee, -$fee, $actor, "Frais de retour ({$percent} % des frais de livraison)");

        $this->earn($courier, EarningType::Return, $courier?->return_commission, $order);
    }

    private function ledger(Order $order, LedgerEntryType $type, int $amount, User $actor, string $description): void
    {
        if ($amount === 0) {
            return;
        }

        MerchantLedgerEntry::create([
            'company_id' => $order->company_id,
            'merchant_id' => $order->merchant_id,
            'type' => $type,
            'amount' => $amount,
            'order_id' => $order->id,
            'description' => $description,
            'created_by' => $actor->id,
        ]);
    }

    private function earn(?Courier $courier, EarningType $type, ?int $amount, Order $order): void
    {
        if ($courier === null || ! $amount) {
            return;
        }

        CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => $type,
            'amount' => $amount,
            'order_id' => $order->id,
            'description' => $type->label().' '.$order->tracking_code,
        ]);
    }
}
