<?php

namespace App\Http\Requests\V1;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $this->user()->can('update', $company)) {
            return false;
        }

        // Statut, adresse (slug), formule et essai : gérés par le super administrateur
        if ($this->hasAny(['status', 'slug', 'plan_id', 'trial_ends_at'])) {
            return $this->user()->can('changeStatus', $company);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Phone::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', ...Company::slugRules($this->route('company'))],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'auto_confirm_orders' => ['sometimes', 'boolean'],
            'default_max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'require_delivery_code' => ['sometimes', 'boolean'],
            'offline_courier_actions' => ['sometimes', 'boolean'],
            'return_fee_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'field_alert_reminder_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'parcel_hold_alert_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'merchant_signup' => ['sometimes', 'boolean'],
            'weekly_report' => ['sometimes', 'boolean'],
            'pickup_assign_alert_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            // Heure limite du jour (HH:MM, heure de l'entreprise) ; vide = aucune
            'daily_cutoff_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'delivery_assign_alert_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'status' => ['sometimes', 'required', new Enum(CompanyStatus::class)],
            // Vide = sans formule (aucune limite)
            'plan_id' => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'address' => 'adresse',
            'status' => 'statut',
            'daily_cutoff_time' => 'heure limite du jour',
            'plan_id' => 'formule',
            'trial_ends_at' => 'fin de l\'essai',
        ];
    }
}
