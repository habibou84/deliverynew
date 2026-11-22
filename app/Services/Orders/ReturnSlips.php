<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\ReturnCause;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retours aux marchands : colis à rendre, résumés par marchand, que l'agence confie à un livreur (mission de retour).
 * Il n'y a plus de bon de retour : la remise se fait chez le marchand en scannant le QR code du livreur
 * (ReturnHandovers), plus sûr qu'une signature.
 */
class ReturnSlips
{
    // Statuts d'où un retour peut partir (colis récupéré, pas encore rendu)
    public const RETURNABLE = [
        OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
        OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::ReturnAssigned,
    ];

    public function __construct(private readonly OrderDispatcher $dispatcher) {}

    /** Colis à rendre (requête) : au dépôt, chez un livreur, ou déjà confiés pour le retour */
    public function candidatesQuery(): Builder
    {
        return Order::query()
            ->where('return_requested', true)
            ->whereNull('return_slip_id')
            ->whereNull('pickup_hub_id')
            ->whereIn('status', OrderStatus::values(self::RETURNABLE));
    }

    /**
     * Colis à rendre, par marchand.
     *
     * @return Collection<int, Order>
     */
    public function candidates(?int $merchantId = null): Collection
    {
        return $this->candidatesQuery()
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->with(['merchant', 'deliveryZone:id,name', 'lastIncidentReason:id,label', 'holder.user', 'returnCourier.user'])
            ->orderBy('merchant_id')->orderBy('id')
            ->get();
    }

    /**
     * Résumé par marchand des colis à rendre : combien, combien chez un livreur, combien repris de l'ancienne
     * application, le plus ancien (jour de livraison prévu). Le plus ancien d'abord.
     *
     * @return Collection<int, object{merchant_id: int, total: int, held: int, legacy: int, oldest: ?string}>
     */
    public function summary(): Collection
    {
        return $this->candidatesQuery()
            ->select('merchant_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN held_by_courier_id IS NOT NULL THEN 1 ELSE 0 END) AS held')
            ->selectRaw('SUM(CASE WHEN legacy_id IS NOT NULL THEN 1 ELSE 0 END) AS legacy')
            ->selectRaw('MIN('.Order::DELIVERY_DAY_SQL.') AS oldest')
            ->groupBy('merchant_id')
            ->orderByRaw('MIN('.Order::DELIVERY_DAY_SQL.')')
            ->get();
    }

    /**
     * L'agence confie à un livreur les colis à rendre d'un marchand : mission de retour pour chacun. La remise se fait
     * chez le marchand par le QR code (voir ReturnHandovers) ; un colis encore chez un autre livreur passe par un relais.
     *
     * @param  list<int>  $orderIds
     * @return int colis confiés
     */
    public function assign(User $actor, Merchant $merchant, Courier $courier, array $orderIds): int
    {
        $orderIds = array_values(array_unique($orderIds));

        return DB::transaction(function () use ($actor, $merchant, $courier, $orderIds) {
            $orders = Order::query()->whereIn('id', $orderIds)->where('merchant_id', $merchant->id)->lockForUpdate()->get();

            if ($orders->count() !== count($orderIds)) {
                throw new BusinessRuleException('Certains colis sont introuvables ou n\'appartiennent pas à ce marchand.', 'order_ids');
            }

            foreach ($orders as $order) {
                $this->assertReturnable($order);
            }

            foreach ($orders as $order) {
                if (! $order->return_requested) {
                    $order->markForReturn(ReturnCause::Company);
                    $order->save();
                }
                $this->dispatcher->assign($actor, $order->fresh(), AssignmentType::Return, $courier);
            }

            return $orders->count();
        });
    }

    private function assertReturnable(Order $order): void
    {
        if ($order->fromWarehouse()) {
            throw new BusinessRuleException("{$order->tracking_code} : commande d'entrepôt, à remettre en stock.", 'order_ids');
        }

        if (! in_array($order->status, self::RETURNABLE, true)) {
            throw new BusinessRuleException("{$order->tracking_code} : impossible de retourner une course « {$order->status->label()} ».", 'order_ids');
        }
    }

    /**
     * Signature dessinée à l'écran (image PNG en data URL).
     */
    public static function decodeSignature(?string $signature): ?string
    {
        if (blank($signature)) {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $signature, $m)) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }

        $data = base64_decode($m[1], true);

        if ($data === false || strlen($data) > 512 * 1024 || ! str_starts_with($data, "\x89PNG")) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }

        return $data;
    }
}
