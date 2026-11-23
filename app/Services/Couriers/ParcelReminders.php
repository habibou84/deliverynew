<?php

namespace App\Services\Couriers;

use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Notifications\OrderAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Rappel au livreur des colis à rendre au dépôt : en fin de journée (19 h, heure de l'entreprise) et quand il
 * passe « Indisponible ». Une fois par jour et par occasion au plus.
 */
class ParcelReminders
{
    public const EVENING_HOUR = 19;

    public function __construct(private readonly ParcelCustody $custody) {}

    public function remind(Courier $courier, string $occasion): bool
    {
        $due = $this->custody->due($courier);
        $user = $courier->user;
        if ($due->isEmpty() || $user === null) {
            return false;
        }

        if (! Cache::add("parcel-reminder:{$courier->id}:{$occasion}:".today()->toDateString(), true, now()->addDay())) {
            return false;
        }

        $n = $due->count();
        $user->notify(new OrderAlert($due->first(), 'parcel_reminder', '📦 Colis à rendre au dépôt',
            "Vous avez {$n} colis à rendre au dépôt : remettez-les au point de caisse, ou déposez-les dans le bac (Ma caisse → Déposer mes colis).",
            ['url' => '/livreur/caisse', 'href' => '/livreur/caisse', 'count' => $n]));

        return true;
    }

    /** Rappel de fin de journée aux livreurs qui ont encore des colis à rendre */
    public function evening(): int
    {
        $sent = 0;
        Company::query()->where('status', 'active')->each(function (Company $company) use (&$sent) {
            if ((int) now($company->timezone ?: config('app.timezone'))->format('G') !== self::EVENING_HOUR) {
                return;
            }

            $courierIds = Order::query()->withoutGlobalScopes()->where('company_id', $company->id)
                ->whereNotNull('held_by_courier_id')->whereNull('parcel_drop_id')->distinct()->pluck('held_by_courier_id');

            Courier::query()->withoutGlobalScopes()->whereIn('id', $courierIds)->with('user')->each(function (Courier $courier) use (&$sent) {
                $sent += (int) $this->remind($courier, 'evening');
            });
        });

        return $sent;
    }
}
