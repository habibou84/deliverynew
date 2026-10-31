<?php

namespace App\Services\Legacy;

use App\Enums\OrderEventType;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\LegacyCashBaseline;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Point de départ de l'argent chez les livreurs pour les courses reprises de l'ancienne application. Là-bas, la
 * remise de l'argent à la caisse (« cashedback_at ») n'était pas toujours enregistrée : des mois de livraisons
 * paraissent encore chez les livreurs. Avant la date choisie, l'argent est compté comme remis ; pour un livreur dont
 * on a compté l'argent, on garde les encaissements les plus récents qui font ce montant. Rien ne bouge dans la caisse
 * (aucun versement, aucun message) ; annulable pendant 7 jours.
 */
class LegacyCashBaselines
{
    public const REF = 'Remis dans l\'ancienne application (point de départ)';

    /** Encaissements de courses reprises encore chez les livreurs */
    public function held(Company $company): Builder
    {
        return CashCollection::withoutGlobalScopes()->where('cash_collections.company_id', $company->id)->inCourierHands()
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('legacy_id'));
    }

    /**
     * Par livreur : ce que Jibiat compte aujourd'hui, et la part encaissée depuis la date.
     *
     * @return array{couriers: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function preview(Company $company, CarbonInterface $cutoff): array
    {
        $start = $cutoff->copy()->startOfDay();
        $rows = $this->held($company)
            ->selectRaw('courier_id, SUM(amount_collected) as total, COUNT(*) as n, MIN(collected_at) as oldest')
            ->selectRaw('SUM(CASE WHEN collected_at >= ? THEN amount_collected ELSE 0 END) as since_total', [$start])
            ->selectRaw('SUM(CASE WHEN collected_at >= ? THEN 1 ELSE 0 END) as since_n', [$start])
            ->groupBy('courier_id')->get();
        $names = Courier::withoutGlobalScopes()->whereIn('id', $rows->pluck('courier_id')->filter())->with('user:id,name')->get()
            ->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name ?? 'Livreur n° '.$c->id]);

        $couriers = $rows->map(fn ($r) => [
            'courier_id' => $r->courier_id === null ? null : (int) $r->courier_id,
            'name' => $r->courier_id === null ? 'Sans livreur' : ($names[$r->courier_id] ?? 'Livreur n° '.$r->courier_id),
            'total' => (int) $r->total,
            'count' => (int) $r->n,
            'oldest' => $r->oldest ? substr((string) $r->oldest, 0, 10) : null,
            'since_total' => (int) $r->since_total,
            'since_count' => (int) $r->since_n,
        ])->sortByDesc('total')->values()->all();

        return [
            'couriers' => $couriers,
            'totals' => [
                'total' => (int) collect($couriers)->sum('total'),
                'since_total' => (int) collect($couriers)->sum('since_total'),
            ],
        ];
    }

    /**
     * Fait le point de départ. $amounts : livreur (0 = sans livreur) → montant vraiment en main ; un livreur absent
     * garde seulement ce qui a été encaissé depuis la date (« tout remis » avant).
     *
     * @param  array<int, int>  $amounts
     */
    public function apply(Company $company, User $by, CarbonInterface $cutoff, array $amounts): LegacyCashBaseline
    {
        $start = $cutoff->copy()->startOfDay();
        $preview = $this->preview($company, $cutoff);
        if ($preview['couriers'] === []) {
            throw new RuntimeException('Aucun argent de l\'ancienne application n\'est compté chez les livreurs.');
        }

        return DB::transaction(function () use ($company, $by, $cutoff, $start, $amounts, $preview) {
            $baseline = LegacyCashBaseline::create([
                'company_id' => $company->id,
                'user_id' => $by->id,
                'cutoff' => $cutoff->toDateString(),
                'lines' => [],
                'status' => 'done',
            ]);

            $lines = [];
            foreach ($preview['couriers'] as $row) {
                $key = $row['courier_id'] ?? 0;
                $counted = array_key_exists($key, $amounts) ? max(0, (int) $amounts[$key]) : null;
                $collections = $this->held($company)
                    ->when($row['courier_id'] === null, fn ($q) => $q->whereNull('courier_id'), fn ($q) => $q->where('courier_id', $row['courier_id']))
                    ->orderByDesc('collected_at')->orderByDesc('id')->get(['cash_collections.id', 'order_id', 'amount_collected', 'collected_at']);

                // Gardés chez le livreur : depuis la date, ou les plus récents qui tiennent dans le montant compté
                $kept = [];
                $sum = 0;
                foreach ($collections as $c) {
                    $keep = $counted === null ? $c->collected_at !== null && $c->collected_at->gte($start) : $sum + $c->amount_collected <= $counted;
                    if (! $keep) {
                        if ($counted !== null) {
                            break;
                        }

                        continue;
                    }
                    $kept[$c->id] = true;
                    $sum += $c->amount_collected;
                }
                $settled = $collections->reject(fn ($c) => isset($kept[$c->id]));
                $this->settle($baseline, $settled->all());

                $lines[] = [
                    'courier_id' => $row['courier_id'],
                    'name' => $row['name'],
                    'mode' => $counted === null ? 'all' : 'amount',
                    'counted' => $counted,
                    'before' => $row['total'],
                    'after' => $sum,
                    'settled' => $settled->count(),
                ];
            }

            $baseline->forceFill([
                'lines' => $lines,
                'settled_count' => (int) collect($lines)->sum('settled'),
                'settled_amount' => (int) collect($lines)->sum(fn ($l) => $l['before'] - $l['after']),
            ])->save();

            return $baseline;
        });
    }

    /** Annule un point de départ (7 jours) : l'argent revient chez les livreurs, sauf ce qui a été réglé depuis */
    public function undo(LegacyCashBaseline $baseline): int
    {
        if (! $baseline->canBeUndone()) {
            throw new RuntimeException('Ce point de départ ne peut plus être annulé.');
        }

        return DB::transaction(function () use ($baseline) {
            $ids = CashCollection::withoutGlobalScopes()->where('legacy_cash_baseline_id', $baseline->id)
                ->whereNull('remittance_id')->where('transaction_ref', self::REF)->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                CashCollection::withoutGlobalScopes()->whereIn('id', $chunk)
                    ->update(['received_by_company' => false, 'company_confirmed_at' => null, 'transaction_ref' => null, 'legacy_cash_baseline_id' => null, 'updated_at' => now()]);
            }
            DB::table('order_events')->where('meta->legacy_cash_baseline', $baseline->id)->delete();
            $baseline->forceFill(['status' => 'undone', 'undone_at' => now()])->save();

            return $ids->count();
        });
    }

    /** Dernier point de départ de l'entreprise, pour l'écran */
    public function describe(?LegacyCashBaseline $baseline): ?array
    {
        if ($baseline === null) {
            return null;
        }

        return [
            'id' => $baseline->id,
            'status' => $baseline->status,
            'cutoff' => $baseline->cutoff->toDateString(),
            'lines' => $baseline->lines,
            'settled_count' => $baseline->settled_count,
            'settled_amount' => (int) $baseline->settled_amount,
            'created_at' => $baseline->created_at->toIso8601String(),
            'undone_at' => $baseline->undone_at?->toIso8601String(),
            'by' => $baseline->user?->name,
            'can_undo' => $baseline->canBeUndone(),
            'undo_until' => $baseline->created_at->copy()->addDays(LegacyCashBaseline::UNDO_DAYS)->toIso8601String(),
        ];
    }

    /** @param  list<CashCollection>  $collections */
    private function settle(LegacyCashBaseline $baseline, array $collections): void
    {
        $note = 'Argent remis dans l\'ancienne application (point de départ du '.$baseline->cutoff->format('d/m/Y').')';
        foreach (array_chunk($collections, 500) as $chunk) {
            CashCollection::withoutGlobalScopes()->whereIn('id', array_map(fn ($c) => $c->id, $chunk))->update([
                'received_by_company' => true,
                // Reprise : l'argent est déjà à l'entreprise, rien à confirmer
                'company_confirmed_at' => now(),
                'transaction_ref' => self::REF,
                'legacy_cash_baseline_id' => $baseline->id,
                'updated_at' => now(),
            ]);
            DB::table('order_events')->insert(array_map(fn ($c) => [
                'order_id' => $c->order_id,
                'type' => OrderEventType::Note->value,
                'actor_type' => 'system',
                'actor_name' => 'Reprise de l\'ancienne application',
                'visible_to_merchant' => false,
                'note' => $note.' : '.Money::format((int) $c->amount_collected),
                'meta' => json_encode(['legacy_cash_baseline' => $baseline->id]),
                'created_at' => now(),
            ], $chunk));
        }
    }
}
