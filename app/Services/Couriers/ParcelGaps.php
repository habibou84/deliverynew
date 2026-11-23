<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Order;
use App\Models\ParcelGap;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderJournal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Écarts de remise : colis que le livreur devait rendre au dépôt et que l'agent n'a pas réceptionnés (motif).
 * Le livreur est prévenu tout de suite et répond : « je l'ai encore » (rien à trancher) ou « je l'ai remis »
 * (contestation : un administrateur tranche, jamais l'agent qui a fait la réception).
 */
class ParcelGaps
{
    public function __construct(
        private readonly ParcelCustody $custody,
        private readonly OrderJournal $journal,
    ) {}

    /**
     * Prévient le livreur : colis rendus, colis non réceptionnés et leur motif, à confirmer dans « Ma caisse ».
     *
     * @param  Collection<int, Order>  $received
     * @param  Collection<int, ParcelGap>  $gaps
     */
    public function notifyReceipt(User $actor, Courier $courier, Collection $received, Collection $gaps, string $title = 'Point au dépôt'): void
    {
        $user = $courier->user;
        $first = $received->first() ?? $gaps->first()?->order;
        if ($user === null || $first === null) {
            return;
        }

        $body = $received->count().' colis rendu'.($received->count() > 1 ? 's' : '').'.';
        if ($gaps->isNotEmpty()) {
            $body .= ' '.$gaps->count().' non réceptionné'.($gaps->count() > 1 ? 's' : '').' : '
                .$gaps->take(4)->map(fn (ParcelGap $g) => $g->order?->tracking_code.' ('.$g->reasonLabel().')')->implode(', ')
                .($gaps->count() > 4 ? '…' : '').'. Confirmez dans Ma caisse.';
        }

        DB::afterCommit(fn () => $user->notify(new OrderAlert($first, 'parcel_receipt', "📦 {$title} avec {$actor->name}", $body, [
            'url' => '/livreur/caisse',
            'href' => '/livreur/caisse',
            'gaps' => $gaps->count(),
        ])));
    }

    /**
     * Écarts que le livreur doit encore confirmer ou contester.
     *
     * @return Collection<int, ParcelGap>
     */
    public function openFor(Courier $courier): Collection
    {
        return ParcelGap::query()->where('courier_id', $courier->id)->whereNull('courier_answer')->whereNull('resolution')
            ->with(['order:id,tracking_code,recipient_name,merchant_id', 'order.merchant:id,business_name', 'reporter:id,name'])
            ->orderBy('id')->get();
    }

    /**
     * Réponse du livreur : kept (il a encore le colis : l'écart est clos) ou handed (il l'a remis : contestation,
     * les administrateurs sont prévenus).
     */
    public function answer(User $courierUser, ParcelGap $gap, string $answer): ParcelGap
    {
        if ($courierUser->courier?->id !== $gap->courier_id) {
            abort(403);
        }
        if (! $gap->isOpen()) {
            throw new BusinessRuleException('Vous avez déjà répondu pour ce colis.', 'answer');
        }

        $gap->forceFill(['courier_answer' => $answer, 'answered_at' => now()]);
        if ($answer === ParcelGap::KEPT) {
            $gap->forceFill(['resolution' => ParcelGap::COURIER, 'resolved_at' => now()]);
        }
        $gap->save();

        $order = $gap->order;
        $this->journal->record($order, $courierUser, OrderEventType::Note, [
            'note' => $answer === ParcelGap::KEPT
                ? 'Le livreur confirme avoir encore le colis (non réceptionné au dépôt).'
                : 'Le livreur conteste : il dit avoir remis le colis au dépôt. À trancher par un administrateur.',
            'visible_to_merchant' => false,
        ]);

        if ($answer === ParcelGap::HANDED) {
            $this->alertAdmins($gap);
        }

        return $gap;
    }

    /**
     * Décision d'un administrateur sur une contestation : at_hub (le colis était au dépôt : il y est noté, l'écart est
     * imputé à l'agent) ou courier (le livreur l'a encore).
     */
    public function resolve(User $actor, ParcelGap $gap, string $decision): ParcelGap
    {
        if (! $gap->isDisputed()) {
            throw new BusinessRuleException('Cet écart n\'attend pas de décision.', 'decision');
        }

        DB::transaction(function () use ($actor, $gap, $decision) {
            $order = Order::query()->lockForUpdate()->findOrFail($gap->order_id);
            $reporter = $gap->reporter?->name ?? 'l\'agent';

            if ($decision === ParcelGap::AT_HUB && $order->held_by_courier_id === $gap->courier_id) {
                $this->custody->toHub($actor, $order, "Écart de remise tranché : le colis était au dépôt (noté non réceptionné par {$reporter})");
            } else {
                $this->journal->record($order, $actor, OrderEventType::Note, [
                    'note' => $decision === ParcelGap::AT_HUB
                        ? "Écart de remise tranché : le colis était au dépôt (noté non réceptionné par {$reporter})"
                        : 'Écart de remise tranché : le colis est toujours chez le livreur.',
                    'visible_to_merchant' => false,
                ]);
            }

            $gap->forceFill(['resolution' => $decision, 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
        });

        $courierUser = $gap->courier?->user;
        if ($courierUser !== null) {
            $code = $gap->order?->tracking_code;
            $courierUser->notify(new OrderAlert($gap->order, 'parcel_gap_decision', "⚖️ Décision sur le colis {$code}",
                $decision === ParcelGap::AT_HUB ? "{$actor->name} a tranché : le colis est bien au dépôt. Il n'est plus noté chez vous."
                    : "{$actor->name} a tranché : le colis reste noté chez vous. Rapportez-le au dépôt.",
                ['url' => '/livreur/caisse', 'href' => '/livreur/caisse']));
        }

        return $gap->refresh();
    }

    /**
     * Données d'un écart pour l'écran des administrateurs, avec l'historique des deux personnes (30 jours).
     *
     * @return array<string, mixed>
     */
    public function data(ParcelGap $gap): array
    {
        $since = now()->subDays(30);

        return [
            'id' => $gap->id,
            'source' => $gap->source,
            'reason' => $gap->reason,
            'reason_label' => $gap->reasonLabel(),
            'note' => $gap->note,
            'created_at' => $gap->created_at,
            'courier_answer' => $gap->courier_answer,
            'answered_at' => $gap->answered_at,
            'resolution' => $gap->resolution,
            'resolved_at' => $gap->resolved_at,
            'resolved_by' => $gap->resolver?->name,
            'parcel_drop_id' => $gap->parcel_drop_id,
            'order' => $gap->order ? [
                'id' => $gap->order->id,
                'tracking_code' => $gap->order->tracking_code,
                'recipient_name' => $gap->order->recipient_name,
                'status_label' => $gap->order->status->label(),
                'merchant' => $gap->order->merchant?->business_name,
                'still_held' => $gap->order->held_by_courier_id === $gap->courier_id,
            ] : null,
            'courier' => ['id' => $gap->courier_id, 'name' => $gap->courier?->user?->name],
            'reporter' => [
                'id' => $gap->reported_by,
                'name' => $gap->reporter?->name,
                // Écarts imputés à l'agent : le colis était en fait au dépôt
                'at_hub_30d' => $gap->reported_by ? ParcelGap::query()->where('reported_by', $gap->reported_by)
                    ->where('resolution', ParcelGap::AT_HUB)->whereNotNull('courier_answer')->where('resolved_at', '>=', $since)->count() : 0,
                'gaps_30d' => $gap->reported_by ? ParcelGap::query()->where('reported_by', $gap->reported_by)->where('created_at', '>=', $since)->count() : 0,
            ],
            'courier_history' => [
                // Contestations rejetées : le livreur disait l'avoir remis, il l'avait encore
                'rejected_30d' => ParcelGap::query()->where('courier_id', $gap->courier_id)->where('courier_answer', ParcelGap::HANDED)
                    ->where('resolution', ParcelGap::COURIER)->where('resolved_at', '>=', $since)->count(),
                'gaps_30d' => ParcelGap::query()->where('courier_id', $gap->courier_id)->where('created_at', '>=', $since)->count(),
            ],
        ];
    }

    private function alertAdmins(ParcelGap $gap): void
    {
        $gap->loadMissing(['order', 'courier.user', 'reporter']);
        $all = User::forCompany($gap->company_id)->where('status', 'active')->role(Role::Admin->value)->get();
        // Jamais l'agent qui a fait la réception, sauf s'il est le seul administrateur
        $admins = $all->filter(fn (User $u) => $u->id !== $gap->reported_by && $u->can(Permission::OrdersDispatch->value));
        if ($admins->isEmpty()) {
            $admins = $all;
        }
        if ($admins->isEmpty()) {
            return;
        }

        $courier = $gap->courier?->user?->name ?? 'Le livreur';
        $reporter = $gap->reporter?->name ?? 'l\'agent';
        Notification::send($admins, new OrderAlert($gap->order, 'parcel_gap',
            "⚠️ Écart de remise · {$gap->order->tracking_code}",
            "{$reporter} l'a noté non réceptionné ({$gap->reasonLabel()}) ; {$courier} dit l'avoir remis. À trancher.",
            ['severity' => 'alert', 'href' => '/admin/colis-livreurs?vue=ecarts', 'courier_id' => $gap->courier_id],
        ));
    }
}
