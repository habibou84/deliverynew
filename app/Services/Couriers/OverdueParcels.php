<?php

namespace App\Services\Couriers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Alerte le dispatch quand des colis restent chez un livreur au-delà du délai de
 * l'entreprise (24 h par défaut). Une seule alerte par colis et par garde,
 * regroupée par livreur.
 */
class OverdueParcels
{
    /**
     * @return int nombre d'alertes envoyées
     */
    public function run(): int
    {
        $sent = 0;

        Company::query()->where('parcel_hold_alert_hours', '>', 0)->each(function (Company $company) use (&$sent) {
            $sent += $this->forCompany($company);
        });

        return $sent;
    }

    private function forCompany(Company $company): int
    {
        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('held_by_courier_id')
            ->whereNull('hold_alerted_at')
            ->where('held_since', '<=', now()->subHours($company->parcel_hold_alert_hours))
            ->with(['holder' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
            ->orderBy('held_since')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        $recipients = User::forCompany($company->id)->where('status', 'active')
            ->role([Role::Admin->value, Role::Dispatcher->value])
            ->get();

        $sent = 0;

        foreach ($orders->groupBy('held_by_courier_id') as $courierId => $parcels) {
            $oldest = $parcels->first();
            $name = $oldest->holder?->user?->name ?? 'un livreur';
            $count = $parcels->count();
            $days = (int) floor($oldest->held_since->diffInHours(now()) / 24);
            $age = $days >= 1 ? "{$days} jour".($days > 1 ? 's' : '') : $company->parcel_hold_alert_hours.' h';

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new OrderAlert(
                    $oldest,
                    'parcel_overdue',
                    $count > 1 ? "📦 {$count} colis toujours chez {$name}" : "📦 Colis {$oldest->tracking_code} toujours chez {$name}",
                    "Non rendu{$this->plural($count)} au dépôt depuis plus de {$age} : ".$parcels->pluck('tracking_code')->take(5)->implode(', ').($count > 5 ? '…' : '').'.',
                    ['custody' => true, 'severity' => 'alert', 'courier_id' => (int) $courierId, 'count' => $count, 'href' => '/admin/colis-livreurs'],
                ));
                $sent++;
            }

            Order::withoutGlobalScopes()->whereKey($parcels->modelKeys())->update(['hold_alerted_at' => now()]);
        }

        return $sent;
    }

    private function plural(int $count): string
    {
        return $count > 1 ? 's' : '';
    }
}
