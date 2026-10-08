<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Assignation des missions (ramassage, livraison, retour) aux livreurs.
 * Le livreur de ramassage et celui de livraison peuvent être différents :
 * chaque mission est une assignation distincte, historisée.
 */
class OrderDispatcher
{
    private const ALLOWED_FROM = [
        'pickup' => [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress],
        'delivery' => [
            OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
            OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
            OrderStatus::DeliveryFailed, OrderStatus::Rescheduled,
        ],
        'return' => [
            OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
            OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::ReturnAssigned,
        ],
    ];

    public function __construct(private readonly OrderJournal $journal) {}

    public function assign(User $actor, Order $order, AssignmentType $type, Courier $courier): OrderAssignment
    {
        return DB::transaction(function () use ($actor, $order, $type, $courier) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if ($courier->company_id !== $order->company_id || ! $courier->user?->isActive()) {
                throw new BusinessRuleException('Ce livreur n\'est pas disponible pour cette entreprise.', 'courier_id');
            }

            if (! in_array($from, self::ALLOWED_FROM[$type->value], true)) {
                throw new BusinessRuleException("Impossible d'assigner un {$this->lower($type)} à une course « {$from->label()} ».", 'type');
            }

            if ($order->fromWarehouse()) {
                match (true) {
                    $type === AssignmentType::Pickup => throw new BusinessRuleException('Cette commande part de l\'entrepôt : pas de ramassage chez le marchand.', 'type'),
                    $type === AssignmentType::Return => throw new BusinessRuleException('Commande d\'entrepôt : remettez le colis en stock à son retour au dépôt.', 'type'),
                    $order->prepared_at === null => throw new BusinessRuleException('Préparez d\'abord la commande à l\'entrepôt.', 'type'),
                    default => null,
                };
            }

            if ($type === AssignmentType::Delivery && $order->attempts_count >= $order->max_attempts) {
                throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'type');
            }

            // Une seule mission active par type : la précédente est annulée (réassignation).
            // On garde le statut d'origine pour pouvoir y revenir si le livreur refuse.
            $previous = $order->activeAssignment($type);
            $statusBefore = $from === self::assignedStatus($type) && $previous?->order_status_before
                ? $previous->order_status_before
                : $from;

            $order->assignments()->active()->where('type', $type->value)
                ->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()]);

            if ($type === AssignmentType::Return) {
                $order->assignments()->active()->where('type', AssignmentType::Delivery->value)
                    ->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()]);
            }

            $assignment = $order->assignments()->create([
                'courier_id' => $courier->id,
                'type' => $type,
                'status' => AssignmentStatus::Assigned,
                'order_status_before' => $statusBefore,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $to = $this->statusAfterAssignment($type, $from);

            if ($from === OrderStatus::Pending) {
                $order->confirmed_at = now();
            }

            $order->{$type->courierColumn()} = $courier->id;
            $order->status = $to;
            $order->save();

            $this->journal->record($order, $actor, OrderEventType::Assigned, [
                'assignment_id' => $assignment->id,
                'from_status' => $from,
                'to_status' => $to,
                'meta' => [
                    'type' => $type->value,
                    'type_label' => $type->label(),
                    'courier_id' => $courier->id,
                    'courier_user_id' => $courier->user_id,
                    'courier_name' => $courier->user->name,
                ],
            ]);

            return $assignment;
        });
    }

    /**
     * Assigne plusieurs courses au même livreur. Les erreurs n'interrompent pas le lot.
     *
     * @param  list<int>  $orderIds
     * @return array{assigned: list<int>, errors: array<int, string>}
     */
    public function assignMany(User $actor, array $orderIds, AssignmentType $type, Courier $courier): array
    {
        $result = ['assigned' => [], 'errors' => []];

        foreach (Order::whereIn('id', $orderIds)->get() as $order) {
            try {
                $this->assign($actor, $order, $type, $courier);
                $result['assigned'][] = $order->id;
            } catch (BusinessRuleException $e) {
                $result['errors'][$order->id] = $e->getMessage();
            }
        }

        foreach (array_diff($orderIds, $result['assigned'], array_keys($result['errors'])) as $missing) {
            $result['errors'][$missing] = 'Course introuvable.';
        }

        return $result;
    }

    /**
     * Réponse du livreur à une mission : acceptation ou refus motivé.
     */
    public function respond(User $courierUser, OrderAssignment $assignment, bool $accept, ?string $reason = null): OrderAssignment
    {
        return DB::transaction(function () use ($courierUser, $assignment, $accept, $reason) {
            $assignment = OrderAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->courier_id !== $courierUser->courier?->id) {
                throw new AuthorizationException('Cette mission ne vous est pas assignée.');
            }

            if ($assignment->status !== AssignmentStatus::Assigned) {
                throw new BusinessRuleException('Cette mission a déjà été traitée.', 'status');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($assignment->order_id);

            if ($accept) {
                $assignment->forceFill(['status' => AssignmentStatus::Accepted, 'accepted_at' => now()])->save();
                $this->journal->record($order, $courierUser, OrderEventType::AssignmentAccepted, [
                    'assignment_id' => $assignment->id,
                    'meta' => ['type' => $assignment->type->value],
                    'visible_to_merchant' => false,
                ]);

                return $assignment;
            }

            $assignment->forceFill([
                'status' => AssignmentStatus::Refused,
                'refusal_reason' => $reason,
                'completed_at' => now(),
            ])->save();

            $from = $order->status;

            // La course revient à son état d'avant l'assignation (une course en attente reste validée)
            if ($from === self::assignedStatus($assignment->type) && $assignment->order_status_before !== null) {
                $order->status = $assignment->order_status_before === OrderStatus::Pending
                    ? OrderStatus::Confirmed
                    : $assignment->order_status_before;
            }

            $order->{$assignment->type->courierColumn()} = null;
            $order->save();

            $this->journal->record($order, $courierUser, OrderEventType::AssignmentRefused, [
                'assignment_id' => $assignment->id,
                'from_status' => $from,
                'to_status' => $order->status,
                'note' => $reason,
                'meta' => ['type' => $assignment->type->value],
                'visible_to_merchant' => false,
            ]);

            return $assignment;
        });
    }

    private static function assignedStatus(AssignmentType $type): OrderStatus
    {
        return match ($type) {
            AssignmentType::Pickup => OrderStatus::PickupAssigned,
            AssignmentType::Delivery => OrderStatus::DeliveryAssigned,
            AssignmentType::Return => OrderStatus::ReturnAssigned,
        };
    }

    private function statusAfterAssignment(AssignmentType $type, OrderStatus $from): OrderStatus
    {
        return match ($type) {
            AssignmentType::Pickup => OrderStatus::PickupAssigned,
            // Livraison planifiée avant le ramassage : la course reste à l'étape ramassage
            AssignmentType::Delivery => $from->isBeforePickup()
                ? ($from === OrderStatus::Pending ? OrderStatus::Confirmed : $from)
                : OrderStatus::DeliveryAssigned,
            AssignmentType::Return => OrderStatus::ReturnAssigned,
        };
    }

    private function lower(AssignmentType $type): string
    {
        return mb_strtolower($type->label());
    }
}
