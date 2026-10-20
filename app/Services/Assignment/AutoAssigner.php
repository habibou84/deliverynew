<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CourierRule;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Assignation automatique des ramassages selon le planning des livreurs.
 *
 * À la validation d'une course (et toutes les cinq minutes pour rattraper), le ramassage va au livreur
 * du planning ce jour-là, ou à son remplaçant. À l'essai, rien n'est envoyé : le choix est noté sur la course.
 * Sans règle qui couvre la course, rien ne change : elle reste à l'équipe (alertes « sans livreur »).
 * Ne fait jamais échouer la création ou la validation d'une course.
 */
class AutoAssigner
{
    public const SOURCE_PLANNING = 'planning';

    public function __construct(
        private readonly CourierPlanning $planning,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
    ) {}

    public function pickup(Order $order): ?OrderAssignment
    {
        try {
            return $this->tryPickup($order);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Rattrapage : courses validées encore sans ramasseur (règle créée ou livreur revenu entre-temps).
     */
    public function run(): int
    {
        $assigned = 0;

        Company::query()->where('status', 'active')->whereIn('auto_pickup', [Company::AUTO_TRIAL, Company::AUTO_ON])
            ->each(function (Company $company) use (&$assigned) {
                Order::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('status', OrderStatus::Confirmed->value)
                    ->whereNull('pickup_hub_id')
                    ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
                    ->orderBy('confirmed_at')
                    ->limit(500)
                    ->get()
                    ->each(function (Order $order) use (&$assigned) {
                        if ($this->pickup($order)) {
                            $assigned++;
                        }
                    });
            });

        return $assigned;
    }

    private function tryPickup(Order $order): ?OrderAssignment
    {
        $company = Company::query()->find($order->company_id);
        $mode = $company?->auto_pickup ?? Company::AUTO_OFF;
        if ($company === null || $mode === Company::AUTO_OFF) {
            return null;
        }

        $order = $order->fresh();
        if ($order->status !== OrderStatus::Confirmed || $order->fromWarehouse() || $order->merchant_id === null
            || $order->assignments()->active()->where('type', AssignmentType::Pickup->value)->exists()) {
            return null;
        }

        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $choice = $this->planning->resolve($order, CourierRule::PICKUP, $today);
        if ($choice === null) {
            return null;
        }

        $who = $choice['courier']->user->name.($choice['backup'] ? ' (remplaçant)' : '');
        $why = "planning « {$choice['label']} »";

        // Essai : on note ce qui aurait été fait, une fois par jour et par course
        if ($mode === Company::AUTO_TRIAL) {
            if (Cache::add("auto-pickup-trial:{$order->id}:{$today}", 1, now()->addDay())) {
                $this->journal->record($order, null, OrderEventType::Note, [
                    'note' => "Essai de l'assignation automatique : le ramassage aurait été confié à {$who}, {$why}.",
                    'visible_to_merchant' => false,
                    'meta' => ['auto_trial' => 'pickup', 'courier_id' => $choice['courier']->id],
                ]);
            }

            return null;
        }

        $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
        if ($actor === null) {
            return null;
        }

        try {
            $assignment = $this->dispatcher->assign($actor, $order, AssignmentType::Pickup, $choice['courier']);
        } catch (BusinessRuleException) {
            return null;
        }
        $assignment->forceFill(['source' => self::SOURCE_PLANNING])->save();

        $this->journal->record($order, null, OrderEventType::Note, [
            'note' => "Ramassage confié automatiquement à {$who}, {$why}.",
            'visible_to_merchant' => false,
            'meta' => ['auto' => 'pickup', 'courier_id' => $choice['courier']->id, 'rule_id' => $choice['rule']->id],
        ]);

        return $assignment;
    }
}
