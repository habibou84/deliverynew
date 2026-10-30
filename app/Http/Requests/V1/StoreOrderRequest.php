<?php

namespace App\Http\Requests\V1;

use App\Enums\FeePayer;
use App\Enums\Role;
use App\Models\Order;
use App\Rules\PhoneNumber;
use App\Services\Orders\ZoneResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Order::class);
    }

    /** @var array<string, string> zones données par leur nom et non reconnues */
    private array $zoneErrors = [];

    /**
     * Zones données par leur nom (« delivery_zone »: « Cocody ») ou le code de leur lieu (« delivery_place ») :
     * remplacées par leur identifiant.
     */
    protected function prepareForValidation(): void
    {
        $this->zoneErrors = app(ZoneResolver::class)->resolveInto($this, $this->user()->company_id);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->zoneErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            // Nouvel e-commerçant créé avec la course : administrateurs et dispatcheurs
            if ($this->filled('new_merchant') && ! $this->user()->hasAnyRole([Role::Admin->value, Role::Dispatcher->value])) {
                $validator->errors()->add('new_merchant', 'Seuls les administrateurs et les dispatcheurs peuvent ajouter un e-commerçant.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $zone = Rule::exists('zones', 'id')->where('company_id', $companyId)->where('is_active', true);

        return [
            // Le personnel choisit le marchand ; un marchand crée pour lui-même
            'merchant_id' => [
                Rule::requiredIf($this->user()->merchant_id === null && ! $this->filled('new_merchant')),
                Rule::prohibitedIf($this->user()->merchant_id !== null || $this->filled('new_merchant')),
                'integer',
                Rule::exists('merchants', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            // E-commerçant pas encore inscrit : créé avec la course, ses accès envoyés par WhatsApp
            'new_merchant' => [Rule::prohibitedIf($this->user()->merchant_id !== null), 'nullable', 'array'],
            'new_merchant.business_name' => ['required_with:new_merchant', 'string', 'max:255'],
            'new_merchant.phone' => ['required_with:new_merchant', 'string', new PhoneNumber],
            'new_merchant.contact_name' => ['nullable', 'string', 'max:255'],
            'new_merchant.pickup_zone_id' => ['nullable', 'integer', $zone],
            'new_merchant.send_access' => ['nullable', 'boolean'],
            'merchant_reference' => ['nullable', 'string', 'max:100'],

            // Commande préparée dans un entrepôt (produits en stock chez l'entreprise)
            'pickup_hub_id' => ['nullable', 'integer', Rule::exists('hubs', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'pickup_zone_id' => ['nullable', 'integer', $zone],
            'pickup_zone' => ['nullable', 'string', 'max:150'],
            'pickup_place' => ['nullable', 'string', 'max:120'],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_landmark' => ['nullable', 'string', 'max:255'],
            'pickup_contact_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', new PhoneNumber],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],

            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', new PhoneNumber],
            'recipient_phone2' => ['nullable', 'string', new PhoneNumber],
            // Identifiant de zone, son nom (« Cocody », « Yopougon Siporex ») ou le code du lieu (« abidjan-cocody-angre »)
            'delivery_zone_id' => ['required_without_all:delivery_zone,delivery_place', 'nullable', 'integer', $zone],
            'delivery_zone' => ['nullable', 'string', 'max:150'],
            'delivery_place' => ['nullable', 'string', 'max:120'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_landmark' => ['nullable', 'string', 'max:255'],
            'delivery_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_scheduled_date' => ['nullable', 'date', 'after_or_equal:today'],
            'delivery_time_slot' => ['nullable', 'string', 'max:20'],

            'description' => ['nullable', 'string', 'max:1000'],
            'package_size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL'])],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'is_fragile' => ['boolean'],
            'is_express' => ['boolean'],
            // API : false quand l'application appelante prévient elle-même l'équipe du marchand
            'notify_merchant' => ['sometimes', 'boolean'],
            'merchant_note' => ['nullable', 'string', 'max:1000'],

            'items_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // Saisie dans l'application ou le back-office : choix explicite, jamais par défaut
            // (API publique : réglage habituel du marchand si absent)
            'fee_payer' => [Rule::requiredIf(! $this->is('api/public/*')), 'nullable', Rule::enum(FeePayer::class)],

            // Articles : produits du stock (réservés) ou articles libres
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.label' => ['nullable', 'required_without:items.*.product_id', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return ['fee_payer.required' => 'Indiquez qui paie la livraison : le marchand ou le client.'];
    }

    public function attributes(): array
    {
        return [
            'merchant_id' => 'marchand',
            'new_merchant.business_name' => 'nom de la boutique',
            'new_merchant.phone' => 'téléphone WhatsApp',
            'new_merchant.contact_name' => 'nom du responsable',
            'new_merchant.pickup_zone_id' => 'zone de ramassage',
            'pickup_zone_id' => 'zone de ramassage',
            'recipient_phone' => 'téléphone du destinataire',
            'recipient_phone2' => 'second téléphone',
            'delivery_zone_id' => 'zone de livraison',
            'delivery_scheduled_date' => 'date de livraison',
            'items_amount' => 'montant des articles',
            'fee_payer' => 'payeur des frais',
            'weight_kg' => 'poids',
            'pickup_hub_id' => 'entrepôt',
            'items' => 'articles',
            'items.*.label' => 'nom de l\'article',
            'items.*.quantity' => 'quantité',
        ];
    }
}
