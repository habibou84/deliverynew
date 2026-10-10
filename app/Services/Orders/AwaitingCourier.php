<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Courses qui attendent un livreur :
 *  - ramassage : course validée, personne n'est désigné pour aller la chercher ;
 *  - livraison : colis récupéré, à l'entrepôt ou reporté à aujourd'hui, sans livreur.
 * Le délai part de l'entrée dans le statut (ou du jour prévu de livraison s'il est plus
 * tard) ; au-delà du seuil de l'entreprise, la course est « en retard » et le dispatch
 * est alerté, puis les administrateurs après trois fois le seuil.
 */
class AwaitingCourier
{
    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    private const DELIVERY_STATUSES = [OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::Rescheduled];

    public function stage(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Confirmed && $order->pickup_hub_id === null => self::PICKUP,
            in_array($order->status, self::DELIVERY_STATUSES, true) && ! $order->return_requested => self::DELIVERY,
            default => null,
        };
    }

    /**
     * Début de l'attente ; null si la course n'attend pas encore (livraison prévue un autre jour).
     */
    public function since(Order $order): ?CarbonInterface
    {
        $since = $order->status_changed_at ?? $order->updated_at ?? now();

        if ($order->delivery_scheduled_date !== null && $order->delivery_scheduled_date->copy()->startOfDay()->gt($since)) {
            $since = $order->delivery_scheduled_date->copy()->startOfDay();
        }

        return $since->isFuture() ? null : $since;
    }

    public function threshold(Company $company, string $stage): int
    {
        return (int) ($stage === self::PICKUP ? $company->pickup_assign_alert_minutes : $company->delivery_assign_alert_minutes);
    }

    /**
     * État affiché dans la liste des courses (null si la course n'attend pas de livreur).
     *
     * @return array{stage: string, since: string, minutes: int, late: bool, threshold_minutes: int}|null
     */
    public function describe(Order $order, Company $company): ?array
    {
        $stage = $this->stage($order);
        $since = $stage ? $this->since($order) : null;
        if ($since === null) {
            return null;
        }

        $minutes = (int) $since->diffInMinutes(now());
        $threshold = $this->threshold($company, $stage);

        return [
            'stage' => $stage,
            'since' => $since->toIso8601String(),
            'minutes' => $minutes,
            'late' => $threshold > 0 && $minutes >= $threshold,
            'threshold_minutes' => $threshold,
        ];
    }

    /**
     * Courses qui attendent un livreur dans une entreprise, avec leur état.
     *
     * @return Collection<int, array{order: Order, stage: string, minutes: int, late: bool}>
     */
    public function waiting(Company $company): Collection
    {
        return Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', OrderStatus::Confirmed->value)->whereNull('pickup_hub_id'))
                ->orWhere(fn (Builder $q) => $q->whereIn('status', OrderStatus::values(self::DELIVERY_STATUSES))->where('return_requested', false)))
            ->get()
            ->map(fn (Order $order) => ($state = $this->describe($order, $company)) ? ['order' => $order, ...$state] : null)
            ->filter()
            ->values();
    }

    /**
     * Compteurs du menu et des onglets : courses en retard, par étape.
     *
     * @return array{pickup: int, delivery: int, total: int}
     */
    public function lateCounts(Company $company): array
    {
        $late = $this->waiting($company)->where('late', true);
        $pickup = $late->where('stage', self::PICKUP)->count();
        $delivery = $late->where('stage', self::DELIVERY)->count();

        return ['pickup' => $pickup, 'delivery' => $delivery, 'total' => $pickup + $delivery];
    }

    /**
     * Alertes : dispatch au-delà du seuil (une fois par course et par statut), puis
     * administrateurs au-delà de trois fois le seuil. Regroupées par étape.
     *
     * @return int nombre de notifications envoyées
     */
    public function alert(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')
            ->where(fn ($q) => $q->where('pickup_assign_alert_minutes', '>', 0)->orWhere('delivery_assign_alert_minutes', '>', 0))
            ->each(function (Company $company) use (&$sent) {
                $late = $this->waiting($company)->where('late', true);

                foreach ([self::PICKUP, self::DELIVERY] as $stage) {
                    $threshold = $this->threshold($company, $stage);
                    $orders = $late->where('stage', $stage)->sortByDesc('minutes');

                    $first = $orders->filter(fn ($w) => $w['order']->unassigned_alerted_at === null);
                    if ($first->isNotEmpty()) {
                        $sent += $this->notify($company, $stage, $first, $threshold, [Role::Admin, Role::Dispatcher], false);
                        Order::withoutGlobalScopes()->whereKey($first->pluck('order.id'))->update(['unassigned_alerted_at' => now()]);
                    }

                    $escalate = $orders->filter(fn ($w) => $w['order']->unassigned_escalated_at === null && $w['minutes'] >= 3 * $threshold);
                    if ($escalate->isNotEmpty()) {
                        $sent += $this->notify($company, $stage, $escalate, 3 * $threshold, [Role::Admin], true);
                        Order::withoutGlobalScopes()->whereKey($escalate->pluck('order.id'))->update(['unassigned_escalated_at' => now()]);
                    }
                }
            });

        return $sent;
    }

    /**
     * @param  Collection<int, array{order: Order, minutes: int}>  $orders
     * @param  list<Role>  $roles
     */
    private function notify(Company $company, string $stage, Collection $orders, int $minutes, array $roles, bool $escalation): int
    {
        $users = User::forCompany($company->id)->where('status', 'active')->role(array_map(fn (Role $r) => $r->value, $roles))->get();
        if ($users->isEmpty()) {
            return 0;
        }

        $count = $orders->count();
        $what = $stage === self::PICKUP ? 'de ramassage' : 'de livraison';
        $codes = $orders->pluck('order.tracking_code')->take(5)->implode(', ').($count > 5 ? '…' : '');
        $title = ($escalation ? '🚨 ' : '⏰ ').($count > 1 ? "{$count} courses" : "Course {$orders->first()['order']->tracking_code}")
            ." toujours sans livreur {$what}";

        Notification::send($users, new OrderAlert(
            $orders->first()['order'],
            'unassigned_'.$stage,
            $title,
            'En attente depuis plus de '.self::duration($minutes)." : {$codes}. Assignez un livreur.",
            ['severity' => 'alert', 'count' => $count, 'href' => '/admin/courses?queue='.($stage === self::PICKUP ? 'to_pickup' : 'to_deliver')],
        ));

        return 1;
    }

    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest ? "{$hours} h ".str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : "{$hours} h";
    }
}
