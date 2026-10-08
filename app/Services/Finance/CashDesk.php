<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Caisse : réception de l'argent encaissé par les livreurs.
 */
class CashDesk
{
    /**
     * Enregistre le versement d'un livreur. Sans liste d'encaissements, tout ce qu'il
     * a en main est soldé. Un manque est retenu sur sa prochaine paie.
     *
     * @param  list<int>|null  $collectionIds
     */
    public function remit(User $actor, Courier $courier, int $amountReceived, ?array $collectionIds = null, ?string $notes = null): CourierRemittance
    {
        return DB::transaction(function () use ($actor, $courier, $amountReceived, $collectionIds, $notes) {
            $collections = CashCollection::query()
                ->where('courier_id', $courier->id)
                ->inCourierHands()
                ->when($collectionIds !== null, fn ($q) => $q->whereIn('id', $collectionIds))
                ->lockForUpdate()
                ->get();

            if ($collectionIds !== null && $collections->count() !== count(array_unique($collectionIds))) {
                throw new BusinessRuleException('Certains encaissements sont introuvables ou déjà versés.', 'collection_ids');
            }

            if ($collections->isEmpty()) {
                throw new BusinessRuleException('Ce livreur n\'a aucun encaissement à verser.', 'courier_id');
            }

            $expected = $collections->sum('amount_collected');

            $remittance = CourierRemittance::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'amount_expected' => $expected,
                'amount_received' => $amountReceived,
                'difference' => $amountReceived - $expected,
                'received_by' => $actor->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            CashCollection::whereIn('id', $collections->modelKeys())->update(['remittance_id' => $remittance->id]);

            if ($remittance->difference < 0) {
                CourierEarning::create([
                    'company_id' => $courier->company_id,
                    'courier_id' => $courier->id,
                    'type' => EarningType::Shortfall,
                    'amount' => $remittance->difference,
                    'remittance_id' => $remittance->id,
                    'description' => 'Manque de caisse du '.now()->format('d/m/Y'),
                    'created_by' => $actor->id,
                ]);
            }

            return $remittance;
        });
    }
}
