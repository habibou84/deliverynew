<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Machine à états des courses : vérifie qui peut faire quelle transition,
 * applique les effets (assignations, tentatives, horodatages) et journalise.
 */
class OrderWorkflow
{
    public function __construct(private readonly OrderJournal $journal) {}

    /**
     * @param  array{
     *     incident_reason_id?: int|null,
     *     note?: string|null,
     *     rescheduled_to?: string|null,
     *     delivery_code?: string|null,
     *     collected_amount?: int|null,
     *     cancel_reason?: string|null,
     *     lat?: float|null,
     *     lng?: float|null,
     * }  $context
     */
    public function transition(User $actor, Order $order, OrderStatus $to, array $context = []): Order
    {
        return DB::transaction(function () use ($actor, $order, $to, $context) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            $reason = $this->resolveReason($order, $context['incident_reason_id'] ?? null);

            // Un échec avec un motif « report » devient directement un report daté
            if ($to === OrderStatus::DeliveryFailed && $reason?->requires_date) {
                $to = OrderStatus::Rescheduled;
            }

            if (! $from->canTransitionTo($to)) {
                throw new BusinessRuleException("Transition impossible : « {$from->label()} » → « {$to->label()} ».", 'status');
            }

            $this->authorizeActor($actor, $order, $from, $to);
            $this->validate($order, $from, $to, $reason, $context);

            $assignment = $this->applyEffects($order, $from, $to, $reason, $context);

            $order->status = $to;
            $order->save();

            $this->journal->record($order, $actor, $reason ? OrderEventType::Incident : OrderEventType::StatusChanged, [
                'assignment_id' => $assignment?->id,
                'from_status' => $from,
                'to_status' => $to,
                'incident_reason_id' => $reason?->id,
                'rescheduled_to' => $context['rescheduled_to'] ?? null,
                'note' => $context['note'] ?? $context['cancel_reason'] ?? null,
                'lat' => $context['lat'] ?? null,
                'lng' => $context['lng'] ?? null,
                'meta' => array_filter([
                    'collected_amount' => $to === OrderStatus::Delivered ? $order->collected_amount : null,
                    'attempts_count' => $order->attempts_count,
                ], fn ($v) => $v !== null),
            ]);

            return $order;
        });
    }

    private function resolveReason(Order $order, ?int $reasonId): ?IncidentReason
    {
        if ($reasonId === null) {
            return null;
        }

        $reason = IncidentReason::availableTo($order->company_id)->find($reasonId);

        if ($reason === null) {
            throw new BusinessRuleException('Motif d\'incident inconnu.', 'incident_reason_id');
        }

        return $reason;
    }

    private function authorizeActor(User $actor, Order $order, OrderStatus $from, OrderStatus $to): void
    {
        if ($actor->can(Permission::OrdersDispatch->value)) {
            return;
        }

        if ($actor->merchant_id !== null) {
            $merchantAllowed = $actor->merchant_id === $order->merchant_id && match ($to) {
                OrderStatus::Cancelled => $from->isBeforePickup(),
                OrderStatus::Rescheduled => $from === OrderStatus::DeliveryFailed,
                default => false,
            };

            if ($merchantAllowed) {
                return;
            }

            throw new AuthorizationException('Cette action est réservée à l\'entreprise de livraison.');
        }

        if ($actor->isCourier()) {
            $courierId = $actor->courier?->id;
            $holds = fn (AssignmentType $type) => $order->assignments()->active()
                ->where('type', $type->value)->where('courier_id', $courierId)->exists();

            $allowed = match ($to) {
                OrderStatus::PickupInProgress, OrderStatus::PickedUp => $holds(AssignmentType::Pickup),
                OrderStatus::Confirmed => in_array($from, [OrderStatus::PickupAssigned, OrderStatus::PickupInProgress], true)
                    && $holds(AssignmentType::Pickup),
                OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::DeliveryFailed => $holds(AssignmentType::Delivery),
                OrderStatus::Rescheduled => $from === OrderStatus::OutForDelivery && $holds(AssignmentType::Delivery),
                // Dépôt au hub : par le livreur qui a le colis en main
                OrderStatus::AtHub => ($from === OrderStatus::PickedUp && $order->pickup_courier_id === $courierId)
                    || $order->delivery_courier_id === $courierId,
                OrderStatus::Returning, OrderStatus::Returned => $holds(AssignmentType::Return),
                default => false,
            };

            if ($allowed) {
                return;
            }

            throw new AuthorizationException('Cette course ne vous est pas assignée pour cette étape.');
        }

        // Agent de dépôt : réception au hub uniquement
        if ($to === OrderStatus::AtHub && $actor->can(Permission::OrdersUpdateStatus->value)) {
            return;
        }

        throw new AuthorizationException;
    }

    private function validate(Order $order, OrderStatus $from, OrderStatus $to, ?IncidentReason $reason, array $context): void
    {
        $stage = $from->isBeforePickup() ? 'pickup' : 'delivery';

        if ($reason !== null && ! $reason->appliesTo($stage)) {
            throw new BusinessRuleException('Ce motif ne s\'applique pas à cette étape.', 'incident_reason_id');
        }

        match ($to) {
            OrderStatus::OutForDelivery => $this->validateOutForDelivery($order),
            OrderStatus::DeliveryFailed => $reason ?? throw new BusinessRuleException('Indiquez le motif de l\'échec.', 'incident_reason_id'),
            OrderStatus::Rescheduled => $this->validateRescheduleDate($context['rescheduled_to'] ?? null),
            // Retour à « Validée » depuis le ramassage = échec du ramassage, motif obligatoire
            OrderStatus::Confirmed => $from === OrderStatus::Pending
                ? null
                : ($reason ?? throw new BusinessRuleException('Indiquez le motif de l\'échec du ramassage.', 'incident_reason_id')),
            OrderStatus::Delivered => $this->validateDelivery($order, $context),
            default => null,
        };
    }

    private function validateOutForDelivery(Order $order): void
    {
        if ($order->activeAssignment(AssignmentType::Delivery) === null) {
            throw new BusinessRuleException('Assignez d\'abord un livreur pour la livraison.', 'status');
        }

        if ($order->attempts_count >= $order->max_attempts) {
            throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'status');
        }
    }

    private function validateRescheduleDate(?string $date): void
    {
        if ($date === null || ! strtotime($date)) {
            throw new BusinessRuleException('Indiquez la nouvelle date de livraison.', 'rescheduled_to');
        }

        if (Carbon::parse($date)->isBefore(today())) {
            throw new BusinessRuleException('La date de report ne peut pas être passée.', 'rescheduled_to');
        }
    }

    private function validateDelivery(Order $order, array $context): void
    {
        if ($order->company->require_delivery_code) {
            $code = (string) ($context['delivery_code'] ?? '');

            if ($code === '' || ! hash_equals((string) $order->delivery_code, $code)) {
                throw new BusinessRuleException('Code de livraison incorrect.', 'delivery_code');
            }
        }

        $collected = $context['collected_amount'] ?? null;

        if ($collected !== null && (! is_int($collected) || $collected < 0)) {
            throw new BusinessRuleException('Montant encaissé invalide.', 'collected_amount');
        }
    }

    /**
     * Met à jour assignations, horodatages et compteurs. Retourne l'assignation concernée.
     */
    private function applyEffects(Order $order, OrderStatus $from, OrderStatus $to, ?IncidentReason $reason, array $context): ?OrderAssignment
    {
        $now = now();
        $pickup = $order->activeAssignment(AssignmentType::Pickup);
        $delivery = $order->activeAssignment(AssignmentType::Delivery);
        $return = $order->activeAssignment(AssignmentType::Return);

        if ($reason !== null) {
            $order->last_incident_reason_id = $reason->id;
        }

        switch ($to) {
            case OrderStatus::Confirmed:
                if ($from === OrderStatus::Pending) {
                    $order->confirmed_at = $now;
                } else {
                    // Échec du ramassage : l'assignation échoue, la course attend un nouveau ramasseur
                    $this->finish($pickup, AssignmentStatus::Failed);
                    $order->pickup_courier_id = null;
                }

                return $pickup;

            case OrderStatus::PickupInProgress:
                $this->start($pickup);

                return $pickup;

            case OrderStatus::PickedUp:
                $this->finish($pickup, AssignmentStatus::Completed);
                $order->picked_up_at = $now;

                return $pickup;

            case OrderStatus::AtHub:
                // Colis rapporté au dépôt sans tentative : la livraison prévue est annulée
                $this->finish($delivery, AssignmentStatus::Cancelled);

                return null;

            case OrderStatus::OutForDelivery:
                $this->start($delivery);

                return $delivery;

            case OrderStatus::Delivered:
                $this->finish($delivery, AssignmentStatus::Completed);
                $order->delivered_at = $now;
                $order->collected_amount = $context['collected_amount'] ?? $order->cod_amount;
                $order->recipient?->increment('deliveries_count');

                return $delivery;

            case OrderStatus::DeliveryFailed:
            case OrderStatus::Rescheduled:
                if ($from === OrderStatus::OutForDelivery) {
                    $this->finish($delivery, AssignmentStatus::Failed);

                    if ($reason?->counts_as_attempt ?? $to === OrderStatus::DeliveryFailed) {
                        $order->attempts_count++;
                        $order->recipient?->increment('failed_count');
                    }
                }

                if ($to === OrderStatus::Rescheduled) {
                    $order->delivery_scheduled_date = $context['rescheduled_to'];
                }

                if ($reason?->triggers_return || $order->attempts_count >= $order->max_attempts) {
                    $order->return_requested = true;
                }

                return $delivery;

            case OrderStatus::Returning:
                $this->start($return);

                return $return;

            case OrderStatus::Returned:
                $this->finish($return, AssignmentStatus::Completed);
                $order->returned_at = $now;

                return $return;

            case OrderStatus::Cancelled:
            case OrderStatus::Rejected:
                $order->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
                $order->cancelled_at = $now;
                $order->cancel_reason = $context['cancel_reason'] ?? $context['note'] ?? null;

                return null;

            default:
                return null;
        }
    }

    private function start(?OrderAssignment $assignment): void
    {
        $assignment?->forceFill([
            'status' => AssignmentStatus::InProgress,
            'accepted_at' => $assignment->accepted_at ?? now(),
            'started_at' => now(),
        ])->save();
    }

    private function finish(?OrderAssignment $assignment, AssignmentStatus $status): void
    {
        $assignment?->forceFill(['status' => $status, 'completed_at' => now()])->save();
    }
}
