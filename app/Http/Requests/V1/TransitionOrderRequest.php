<?php

namespace App\Http\Requests\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changement de statut d'une course. Les droits fins (qui peut faire quelle
 * transition) sont vérifiés par OrderWorkflow.
 */
class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('order'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'incident_reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
            'rescheduled_to' => ['nullable', 'date'],
            'delivery_code' => ['nullable', 'string', 'max:10'],
            'collected_amount' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::atDelivery()))],
            'received_by_company' => ['nullable', 'boolean'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'cancel_reason' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'statut',
            'incident_reason_id' => 'motif',
            'rescheduled_to' => 'date de report',
            'delivery_code' => 'code de livraison',
            'collected_amount' => 'montant encaissé',
            'payment_method' => 'mode de paiement',
            'transaction_ref' => 'référence de transaction',
        ];
    }
}
