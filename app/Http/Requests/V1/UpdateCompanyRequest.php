<?php

namespace App\Http\Requests\V1;

use App\Enums\CompanyStatus;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $this->user()->can('update', $company)) {
            return false;
        }

        // Le statut et l'identifiant (slug) sont gérés par le super administrateur
        if ($this->hasAny(['status', 'slug'])) {
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
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('companies', 'slug')->ignore($this->route('company'))],
            'phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'auto_confirm_orders' => ['sometimes', 'boolean'],
            'default_max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'require_delivery_code' => ['sometimes', 'boolean'],
            'return_fee_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'field_alert_reminder_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'parcel_hold_alert_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'required', new Enum(CompanyStatus::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'address' => 'adresse',
            'status' => 'statut',
        ];
    }
}
