<?php

namespace App\Services\Orders;

use App\Enums\OrderEventType;
use App\Events\OrderChanged;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Écrit le journal immuable d'une course et déclenche, après validation de la
 * transaction, la diffusion temps réel et les notifications.
 */
class OrderJournal
{
    public function __construct(private readonly OrderNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(Order $order, ?User $actor, OrderEventType $type, array $attributes = []): OrderEvent
    {
        $event = $order->events()->create([
            ...$attributes,
            'type' => $type,
            'actor_type' => $attributes['actor_type'] ?? ($actor ? 'user' : 'system'),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->primaryRole()?->value,
            'visible_to_merchant' => $attributes['visible_to_merchant'] ?? true,
        ]);

        DB::afterCommit(function () use ($order, $event, $actor) {
            OrderChanged::dispatch($order, $event);
            $this->notifier->notify($order, $event, $actor);
        });

        return $event;
    }
}
