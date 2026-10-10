<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PayCalc;
use App\Enums\PayEvent;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PayPlanResource;
use App\Models\Courier;
use App\Models\PayPlan;
use App\Models\Zone;
use App\Services\Finance\CourierPay;
use App\Services\Finance\PayPlanTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans de rémunération des livreurs (Réglages › Paie des livreurs).
 */
class PayPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = PayPlan::query()
            ->with(['rules', 'owner.user'])
            ->withCount('couriers')
            ->orderByRaw('courier_id is not null')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => PayPlanResource::collection($plans)->resolve($request),
            'meta' => [
                'events' => collect(PayEvent::cases())->map(fn (PayEvent $e) => ['value' => $e->value, 'label' => $e->label()]),
                'calcs' => collect(PayCalc::cases())->map(fn (PayCalc $c) => ['value' => $c->value, 'label' => $c->label()]),
                'templates' => collect(PayPlanTemplates::all())->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'description' => $t['description']]),
            ],
        ]);
    }

    public function show(PayPlan $payPlan): PayPlanResource
    {
        return PayPlanResource::make($payPlan->load(['rules', 'owner.user'])->loadCount('couriers'));
    }

    /**
     * Nouveau plan : depuis un modèle, en copie d'un plan existant, ou plan personnel
     * d'un livreur (copie du plan qu'il a aujourd'hui, puis attribué).
     */
    public function store(Request $request, CourierPay $pay): PayPlanResource
    {
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'name' => ['required_without:courier_id', 'nullable', 'string', 'max:100'],
            'template' => ['nullable', Rule::in(collect(PayPlanTemplates::all())->pluck('key'))],
            'copy_from' => ['nullable', 'integer', Rule::exists('pay_plans', 'id')->where('company_id', $companyId)],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $companyId)],
        ]);

        $plan = DB::transaction(function () use ($data, $companyId, $pay) {
            $courier = isset($data['courier_id']) ? Courier::with(['user', 'payPlan'])->find($data['courier_id']) : null;
            if ($courier !== null && $courier->payPlan?->courier_id === $courier->id) {
                return $courier->payPlan; // déjà personnalisé
            }

            $source = match (true) {
                isset($data['copy_from']) => PayPlan::with('rules')->find($data['copy_from']),
                $courier !== null => $pay->planFor($courier),
                default => null,
            };

            if ($source) {
                $attributes = $source->only(['pickup_mode', 'pickup_extra_parcel_amount', 'min_amount', 'max_amount']);
                $rules = $source->rules->map(fn ($r) => [
                    'event' => $r->event->value, 'calc' => $r->calc->value, 'amount' => $r->amount, 'percent' => $r->percent,
                    'zone_amounts' => $r->zone_amounts, 'conditions' => $r->conditions, 'label' => $r->label,
                ])->all();
            } else {
                $template = PayPlanTemplates::find($data['template'] ?? 'empty');
                [$attributes, $rules] = [$template['plan'], $template['rules']];
            }

            $plan = PayPlan::create([
                ...$attributes,
                'company_id' => $companyId,
                'courier_id' => $courier?->id,
                'name' => $data['name'] ?? 'Plan de '.$courier?->user?->name,
                'is_default' => false,
            ]);
            $this->syncRules($plan, $rules);

            $courier?->update(['pay_plan_id' => $plan->id]);

            return $plan;
        });

        return $this->show($plan);
    }

    public function update(Request $request, PayPlan $payPlan): PayPlanResource
    {
        $companyId = $payPlan->company_id;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'pickup_mode' => ['sometimes', Rule::in([PayPlan::PICKUP_PER_PARCEL, PayPlan::PICKUP_PER_VISIT])],
            'pickup_extra_parcel_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'min_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'max_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_amount'],
            'rules' => ['sometimes', 'array', 'max:100'],
            'rules.*.event' => ['required', Rule::enum(PayEvent::class)],
            'rules.*.calc' => ['required', Rule::enum(PayCalc::class)],
            'rules.*.amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Pourcentage et grille ne comptent que pour leur calcul : ignorés sinon
            'rules.*.percent' => ['exclude_unless:rules.*.calc,percent_fee,percent_collected', 'required', 'numeric', 'min:0', 'max:100'],
            'rules.*.zone_amounts' => ['exclude_unless:rules.*.calc,zone_grid', 'nullable', 'array'],
            'rules.*.zone_amounts.*' => ['integer', 'min:0', 'max:1000000'],
            'rules.*.label' => ['nullable', 'string', 'max:100'],
            'rules.*.conditions' => ['nullable', 'array'],
            'rules.*.conditions.zone_ids' => ['nullable', 'array'],
            'rules.*.conditions.zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'rules.*.conditions.express' => ['nullable', 'boolean'],
            'rules.*.conditions.fragile' => ['nullable', 'boolean'],
            'rules.*.conditions.vehicle_types' => ['nullable', 'array'],
            'rules.*.conditions.vehicle_types.*' => [Rule::enum(VehicleType::class)],
            'rules.*.conditions.incident_reason_ids' => ['nullable', 'array'],
            'rules.*.conditions.incident_reason_ids.*' => ['integer', Rule::exists('incident_reasons', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))],
        ], [], [
            'rules.*.event' => 'étape',
            'rules.*.calc' => 'calcul',
            'rules.*.amount' => 'montant',
            'rules.*.percent' => 'pourcentage',
            'rules.*.zone_amounts' => 'grille par zone',
            'rules.*.zone_amounts.*' => 'montant de la zone',
            'rules.*.label' => 'libellé',
            'rules.*.conditions.zone_ids.*' => 'zone',
            'rules.*.conditions.vehicle_types.*' => 'véhicule',
            'rules.*.conditions.incident_reason_ids.*' => 'motif d\'échec',
            'pickup_extra_parcel_amount' => 'montant par colis supplémentaire',
            'min_amount' => 'minimum',
            'max_amount' => 'plafond',
        ]);

        if (($data['is_default'] ?? false) && $payPlan->courier_id !== null) {
            throw ValidationException::withMessages(['is_default' => 'Un plan personnel ne peut pas être le plan par défaut.']);
        }
        if (array_key_exists('is_default', $data) && ! $data['is_default'] && $payPlan->is_default) {
            throw ValidationException::withMessages(['is_default' => 'Choisissez un autre plan par défaut : il en faut toujours un.']);
        }
        foreach ($data['rules'] ?? [] as $i => $rule) {
            $zones = array_keys($rule['zone_amounts'] ?? []);
            if ($zones && Zone::forCompany($companyId)->whereIn('id', $zones)->count() !== count($zones)) {
                throw ValidationException::withMessages(["rules.$i.zone_amounts" => 'Zone inconnue dans la grille.']);
            }
        }

        DB::transaction(function () use ($payPlan, $data) {
            if ($data['is_default'] ?? false) {
                PayPlan::forCompany($payPlan->company_id)->where('id', '!=', $payPlan->id)->update(['is_default' => false]);
            }
            $payPlan->update(collect($data)->except('rules')->all());
            if (array_key_exists('rules', $data)) {
                $this->syncRules($payPlan, $data['rules']);
            }
        });

        return $this->show($payPlan->refresh());
    }

    /**
     * Suppression : les livreurs du plan reviennent au plan par défaut. Les gains
     * déjà acquis gardent leurs montants.
     */
    public function destroy(PayPlan $payPlan): Response
    {
        if ($payPlan->is_default) {
            throw ValidationException::withMessages(['plan' => 'Le plan par défaut ne peut pas être supprimé : choisissez d\'abord un autre plan par défaut.']);
        }

        DB::transaction(function () use ($payPlan) {
            Courier::forCompany($payPlan->company_id)->where('pay_plan_id', $payPlan->id)->update(['pay_plan_id' => null]);
            $payPlan->delete();
        });

        return response()->noContent();
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function syncRules(PayPlan $plan, array $rules): void
    {
        $plan->rules()->delete();

        foreach (array_values($rules) as $i => $rule) {
            $conditions = array_filter($rule['conditions'] ?? [], fn ($v) => $v !== null && $v !== false && $v !== []);
            $plan->rules()->create([
                'event' => $rule['event'],
                'calc' => $rule['calc'],
                'amount' => (int) ($rule['amount'] ?? 0),
                'percent' => in_array($rule['calc'], [PayCalc::PercentFee->value, PayCalc::PercentCollected->value], true) ? $rule['percent'] ?? 0 : null,
                'zone_amounts' => $rule['calc'] === PayCalc::ZoneGrid->value ? array_map('intval', $rule['zone_amounts'] ?? []) : null,
                'conditions' => $conditions ?: null,
                'label' => $rule['label'] ?? null,
                'sort_order' => $i,
            ]);
        }

        $plan->unsetRelation('rules');
    }
}
