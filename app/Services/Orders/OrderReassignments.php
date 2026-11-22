<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\AutoAssigner;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Colis en retour (refusé, tentatives épuisées…) envoyé à un autre client du même e-commerçant qui a commandé le même
 * article, au lieu de revenir à la boutique :
 *  - la course d'origine se termine « Réaffecté à LV-… », sans frais de retour ni mission de retour ;
 *  - la nouvelle course commence à « Récupéré » (ou « Au dépôt »), chez le livreur qui a le colis, sans ramassage ;
 *  - l'étiquette est remplacée (« Repris de LV-… ») puis confirmée : scan ou 4 derniers caractères du code.
 * L'e-commerçant ou l'agence réaffecte ; l'e-commerçant est prévenu quand c'est l'agence.
 */
class OrderReassignments
{
    /** Colis en retour, pas encore rendu : peut partir avec une autre course */
    private const RETURNING = [OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::AtHub, OrderStatus::ReturnAssigned, OrderStatus::Returning];

    /** Course pas encore ramassée : peut partir avec un colis en retour au lieu d'un ramassage */
    private const WAITING = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress];

    public function __construct(
        private readonly OrderJournal $journal,
        private readonly StockKeeper $stock,
        private readonly OrderService $orders,
    ) {}

    public function isReturning(Order $order): bool
    {
        return in_array($order->status, self::RETURNING, true)
            && ($order->return_requested || in_array($order->status, [OrderStatus::ReturnAssigned, OrderStatus::Returning], true))
            && ! $order->fromWarehouse();
    }

    public function isWaiting(Order $order): bool
    {
        return in_array($order->status, self::WAITING, true) && ! $order->fromWarehouse() && $order->reassigned_from_order_id === null;
    }

    /**
     * Courses du même e-commerçant qui attendent le même article (pour un colis en retour), les plus anciennes d'abord.
     *
     * @return Collection<int, Order>
     */
    public function candidatesFor(Order $returning): Collection
    {
        if (! $this->isReturning($returning)) {
            return collect();
        }
        $keys = $this->keys($returning);

        return Order::query()->where('merchant_id', $returning->merchant_id)->whereKeyNot($returning->id)
            ->whereIn('status', OrderStatus::values(self::WAITING))->whereNull('pickup_hub_id')->whereNull('reassigned_from_order_id')
            ->with(['items', 'deliveryZone.parent'])->orderBy('id')->limit(200)->get()
            ->filter(fn (Order $o) => $this->keys($o)->intersect($keys)->isNotEmpty())->values();
    }

    /**
     * Colis en retour de l'e-commerçant qui contiennent ces articles (pour une course qu'on enregistre).
     *
     * @param  list<array{product_id?: ?int, label?: ?string}>  $items
     * @return Collection<int, Order>
     */
    public function returningFor(Merchant $merchant, array $items, ?string $description, ?int $exceptOrderId = null): Collection
    {
        $keys = $this->keysOf(collect($items)->pluck('product_id')->filter()->all(), [...collect($items)->pluck('label')->filter()->all(), ...$this->split($description)]);
        if ($keys->isEmpty()) {
            return collect();
        }

        return Order::query()->where('merchant_id', $merchant->id)->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->whereIn('status', OrderStatus::values(self::RETURNING))->whereNull('pickup_hub_id')
            ->with(['items', 'deliveryZone.parent'])->orderBy('id')->limit(200)->get()
            ->filter(fn (Order $o) => $this->isReturning($o) && $this->keys($o)->intersect($keys)->isNotEmpty())->values();
    }

    /** Le colis de cette course en retour peut-il partir avec cette course ? */
    public function assertPossible(Order $returning, Order $target): void
    {
        if ($returning->merchant_id !== $target->merchant_id) {
            throw new BusinessRuleException('Les deux courses doivent être du même e-commerçant.', 'order_id');
        }
        if (! $this->isReturning($returning)) {
            throw new BusinessRuleException("Le colis {$returning->tracking_code} n'est pas en retour (ou il est déjà rendu) : il ne peut pas être réaffecté.", 'order_id');
        }
        if (! $this->isWaiting($target)) {
            throw new BusinessRuleException("La course {$target->tracking_code} n'attend plus de colis (déjà ramassée, terminée ou déjà réaffectée).", 'order_id');
        }
    }

    /**
     * Le colis de $returning part avec $target : $returning se termine « Réaffecté », $target commence sans ramassage.
     */
    public function reassign(User $actor, Order $returning, Order $target): Order
    {
        $target = $this->move($actor, $returning, $target);
        // Colis prêt : livraison confiée automatiquement si l'entreprise le veut (comme un colis récupéré)
        app(AutoAssigner::class)->delivery($target);

        return $target->fresh();
    }

    private function move(User $actor, Order $returning, Order $target): Order
    {
        return DB::transaction(function () use ($actor, $returning, $target) {
            $returning = Order::query()->lockForUpdate()->findOrFail($returning->id);
            $target = Order::query()->lockForUpdate()->findOrFail($target->id);
            $this->assertPossible($returning, $target);

            $holder = $returning->held_by_courier_id;
            $atHub = $returning->status === OrderStatus::AtHub && $holder === null;
            $now = now();

            // Course d'origine : plus de retour (mission retirée), articles plus réservés, statut final
            $from = $returning->status;
            $returning->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
            $this->stock->release($returning, $actor, "Colis réaffecté à {$target->tracking_code}");
            $returning->forceFill(['status' => OrderStatus::Reassigned, 'reassigned_to_order_id' => $target->id]);
            $returning->handTo(null);
            $returning->save();
            $this->journal->record($returning, $actor, OrderEventType::StatusChanged, [
                'from_status' => $from,
                'to_status' => OrderStatus::Reassigned,
                'note' => "Réaffecté à {$target->tracking_code} : le colis part chez ".($target->recipient_name ?: 'un autre client').' au lieu de revenir à la boutique (pas de frais de retour).',
                'meta' => ['reassignment' => true, 'reassigned_to' => $target->tracking_code],
            ]);

            // Nouvelle course : le colis est déjà chez le livreur (ou au dépôt), pas de ramassage
            $previous = $target->status;
            $target->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
            $to = $atHub ? OrderStatus::AtHub : OrderStatus::PickedUp;
            $target->forceFill(['status' => $to, 'reassigned_from_order_id' => $returning->id, 'picked_up_at' => $now]);
            $target->handTo($atHub ? null : $holder);
            $target->save();
            $this->journal->record($target, $actor, OrderEventType::StatusChanged, [
                'from_status' => $previous,
                'to_status' => $to,
                'note' => "Colis repris de {$returning->tracking_code} (en retour) : pas de ramassage. Étiquette à changer.",
                'meta' => ['reassignment' => true, 'reassigned_from' => $returning->tracking_code],
            ]);

            // Décidé par l'agence : l'e-commerçant est prévenu (note visible, cloche)
            if ($actor->merchant_id === null) {
                $this->orders->addNote($actor, $returning, "Votre colis {$returning->tracking_code} ne vous sera pas retourné : il part chez "
                    .($target->recipient_name ?: 'votre client')." avec la course {$target->tracking_code}, qui avait commandé le même article.");
            }

            return $target;
        });
    }

    /**
     * Nouvelle étiquette collée : scan du QR code (code complet) ou 4 derniers caractères du code de la course.
     */
    public function confirmLabel(User $actor, Order $order, string $code): Order
    {
        if ($order->reassigned_from_order_id === null) {
            throw new BusinessRuleException('Cette course n\'a pas d\'étiquette à changer.', 'code');
        }
        $typed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $expected = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $order->tracking_code));
        if ($typed === '' || ($typed !== $expected && $typed !== substr($expected, -4))) {
            throw new BusinessRuleException('Ce n\'est pas le code de la nouvelle étiquette : scannez-la, ou tapez les 4 derniers caractères de son code.', 'code');
        }

        if ($order->label_confirmed_at === null) {
            $order->forceFill(['label_confirmed_at' => now()])->save();
            $this->journal->record($order, $actor, OrderEventType::Note, [
                'note' => 'Nouvelle étiquette collée sur le colis repris de '.$order->linkedCode('reassignedFrom').'.',
                'visible_to_merchant' => false,
            ]);
        }

        return $order->fresh();
    }

    /**
     * Ce qui identifie les articles d'une course : produits du catalogue, sinon libellés (« Robe wax M »).
     *
     * @return Collection<int, string>
     */
    private function keys(Order $order): Collection
    {
        $order->loadMissing('items');

        return $this->keysOf(
            $order->items->pluck('product_id')->filter()->all(),
            [...$order->items->pluck('label')->filter()->all(), ...($order->items->isEmpty() ? $this->split($order->description) : [])],
        );
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<string>  $labels
     * @return Collection<int, string>
     */
    private function keysOf(array $productIds, array $labels): Collection
    {
        return collect($productIds)->map(fn ($id) => "p:{$id}")
            ->merge(collect($labels)->map(fn ($l) => self::normalize($l))->filter()->map(fn ($l) => "l:{$l}"))
            ->unique()->values();
    }

    /** « 2 × Robe wax, 1 sac » → ['Robe wax', '1 sac'] */
    private function split(?string $description): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) $description))));
    }

    /** « 2 × Robe wax — M » → « robe wax m » */
    public static function normalize(string $label): string
    {
        $plain = mb_strtolower(trim(\Normalizer::normalize($label, \Normalizer::FORM_D) ?: $label));
        $plain = preg_replace('/\p{Mn}+/u', '', $plain);
        $plain = preg_replace('/^\d+\s*[x×*]\s*/u', '', $plain);
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $plain);

        return trim(preg_replace('/\s+/', ' ', $plain));
    }
}
