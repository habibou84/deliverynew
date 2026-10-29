<?php

namespace App\Services\Legacy;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\LegacyPurge;
use App\Models\LegacyRecheck;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recomparaison complète avec l'ancienne application : toutes les courses reprises sont relues par paquets ; les
 * modifiées sont mises à jour comme par la copie de chaque minute. Une commande effacée là-bas (elle n'est plus
 * renvoyée) est annulée sans message quand aucun argent n'est en jeu ; sinon (livrée, argent chez un livreur ou dû à
 * l'e-commerçant, colis chez un livreur, ou après la bascule) elle va dans « Supprimées à vérifier ».
 */
class LegacyRechecks
{
    public const BATCH = 200;

    public function __construct(
        private readonly LegacyOrdersSync $sync,
        private readonly LegacyOrdersImporter $importer,
        private readonly LegacyOrdersPurge $purges,
    ) {}

    /** Courses reprises à relire (les nettoyées et les supprimées déjà traitées n'existent plus là-bas) */
    public function scope(Company $company): Builder
    {
        return Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id')
            ->whereNull('legacy_purged_at')->whereNull('legacy_deleted_at')->whereNull('deleted_at');
    }

    public function start(Company $company, User $by): LegacyRecheck
    {
        if (LegacyRecheck::query()->where('company_id', $company->id)->where('status', 'running')->exists()) {
            throw new RuntimeException('Une recomparaison est déjà en cours : attendez qu\'elle se termine.');
        }
        if (! $company->legacy_sync_url || ! $company->legacy_sync_key) {
            throw new RuntimeException('Indiquez d\'abord l\'adresse de l\'ancienne application et créez la clé de lecture.');
        }

        return LegacyRecheck::create([
            'company_id' => $company->id,
            'user_id' => $by->id,
            'source' => 'manual',
            'status' => 'running',
            'total' => $this->scope($company)->count(),
            'counts' => self::emptyCounts(),
        ]);
    }

    /** Une étape : un paquet relu. Renvoie true quand la recomparaison est finie (ou arrêtée sur une erreur). */
    public function step(LegacyRecheck $recheck): bool
    {
        $company = Company::findOrFail($recheck->company_id);
        $orders = $this->scope($company)->where('id', '>', $recheck->cursor)->orderBy('id')->limit(self::BATCH)->get();

        try {
            $counts = $this->compare($company, $orders, $recheck);
        } catch (RuntimeException $e) {
            $recheck->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();

            return true;
        }

        $recheck->forceFill([
            'cursor' => $orders->max('id') ?? $recheck->cursor,
            'done' => $recheck->done + $orders->count(),
            'counts' => self::add($recheck->counts ?? self::emptyCounts(), $counts),
        ])->save();

        if ($orders->count() < self::BATCH) {
            $recheck->forceFill(['status' => 'done', 'finished_at' => now()])->save();

            return true;
        }

        return false;
    }

    /**
     * Relit un paquet de courses : mises à jour, et suppressions repérées (commandes plus renvoyées).
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, int>
     */
    public function compare(Company $company, Collection $orders, ?LegacyRecheck $recheck): array
    {
        $counts = self::emptyCounts();
        if ($orders->isEmpty()) {
            return $counts;
        }

        $this->importer->for($company);
        $page = $this->sync->get($company, 'commands', ['ids' => $orders->pluck('legacy_id')->implode(',')]);
        if (! is_array($page['data'] ?? null)) {
            throw new RuntimeException('Réponse inattendue de l\'ancienne application.');
        }
        // Aucune commande renvoyée pour tout un paquet : base vide ou mauvaise adresse, on ne supprime rien
        if ($page['data'] === [] && $orders->count() >= 5) {
            throw new RuntimeException('L\'ancienne application ne renvoie aucune de ces commandes : vérifiez son adresse et sa base avant de recommencer.');
        }

        $returned = [];
        foreach ($page['data'] as $command) {
            $returned[(string) $command['id']] = true;
            $result = $this->importer->import($command);
            $key = in_array($result['action'], ['created', 'updated'], true) ? 'updated' : $result['action'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $purge = null;
        foreach ($orders->reject(fn (Order $o) => isset($returned[(string) $o->legacy_id])) as $order) {
            $outcome = $this->deleted($company, $order->fresh(), function () use ($company, $recheck, &$purge) {
                return $purge ??= $this->purgeFor($company, $recheck);
            });
            $counts[$outcome]++;
        }

        return $counts;
    }

    /** Relecture de nuit des courses encore en cours : mises à jour et suppressions (gardée seulement s'il y en a) */
    public function nightly(Company $company, Collection $orders): void
    {
        $recheck = LegacyRecheck::create(['company_id' => $company->id, 'source' => 'nightly', 'status' => 'running',
            'total' => $orders->count(), 'counts' => self::emptyCounts()]);
        try {
            foreach ($orders->chunk(self::BATCH) as $chunk) {
                $counts = $this->compare($company, $chunk->values(), $recheck);
                $recheck->forceFill(['counts' => self::add($recheck->counts, $counts), 'done' => $recheck->done + $chunk->count()])->save();
            }
        } finally {
            $deleted = ($recheck->counts['deleted_cancelled'] ?? 0) + ($recheck->counts['deleted_to_check'] ?? 0) + ($recheck->counts['deleted_closed'] ?? 0);
            $deleted === 0 ? $recheck->delete() : $recheck->forceFill(['status' => 'done', 'finished_at' => now()])->save();
        }
    }

    /**
     * Commande effacée dans l'ancienne application.
     *
     * @param  callable(): LegacyPurge  $purge
     */
    private function deleted(Company $company, Order $order, callable $purge): string
    {
        $order->forceFill(['legacy_deleted_at' => now()])->save();
        $money = $this->hasMoney($order);

        // Déjà terminée sans argent en jeu : rien à faire
        if (! $money && in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true)) {
            $order->forceFill(['legacy_deleted_kept_at' => now()])->save();
            $this->note($order, 'Supprimée dans l\'ancienne application (course déjà terminée ici).');

            return 'deleted_closed';
        }

        $switched = $company->legacy_switch_at !== null && $company->legacy_switch_at->isPast();
        if ($money || $switched || $order->status === OrderStatus::Delivered || $order->held_by_courier_id !== null) {
            $this->note($order, 'Supprimée dans l\'ancienne application : à vérifier (Réglages › Reprise › Supprimées à vérifier).');

            return 'deleted_to_check';
        }

        $this->purges->cancelDeleted($purge(), $order);

        return 'deleted_cancelled';
    }

    /** Argent encore en jeu : encaissement chez un livreur ou écritures pas encore reversées à l'e-commerçant */
    public function hasMoney(Order $order): bool
    {
        return $order->cashCollection()->inCourierHands()->exists()
            || MerchantLedgerEntry::withoutGlobalScopes()->where('order_id', $order->id)->whereNull('payout_id')->exists();
    }

    /** Supprimées à vérifier : pas encore gardées ni annulées */
    public function toCheck(Company $company): Builder
    {
        return Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_deleted_at')
            ->whereNull('legacy_deleted_kept_at')->whereNull('legacy_purged_at');
    }

    /** Garder la course dans Jibiat */
    public function keep(Order $order): void
    {
        $order->forceFill(['legacy_deleted_kept_at' => now()])->save();
        $this->note($order, 'Supprimée dans l\'ancienne application : gardée dans Jibiat.');
    }

    /** Annuler la course : l'argent pas encore réglé disparaît (elle n'a jamais existé) */
    public function cancel(Order $order): void
    {
        if ($order->cashCollection()->whereNotNull('remittance_id')->exists()
            || MerchantLedgerEntry::withoutGlobalScopes()->where('order_id', $order->id)->whereNotNull('payout_id')->exists()) {
            throw new RuntimeException('L\'argent de cette course est déjà passé à la caisse ou reversé : traitez-la depuis sa fiche.');
        }

        DB::transaction(function () use ($order) {
            MerchantLedgerEntry::withoutGlobalScopes()->where('order_id', $order->id)->whereNull('payout_id')->delete();
            $order->cashCollection()->whereNull('remittance_id')->delete();
            $this->purges->cancelDeleted(null, $order);
        });
    }

    public function undo(LegacyRecheck $recheck): int
    {
        if (! $recheck->canBeUndone() || $recheck->purge === null) {
            throw new RuntimeException('Les annulations de cette recomparaison ne peuvent plus être défaites.');
        }
        // Remises comme avant, puis dans « Supprimées à vérifier » (la suppression reste vraie)
        $restored = $this->purges->undo($recheck->purge);
        $recheck->forceFill(['status' => 'undone', 'undone_at' => now()])->save();

        return $restored;
    }

    /** @return array<string, mixed>|null */
    public function describe(?LegacyRecheck $recheck): ?array
    {
        if ($recheck === null) {
            return null;
        }

        return [
            'id' => $recheck->id,
            'source' => $recheck->source,
            'status' => $recheck->status,
            'done' => $recheck->done,
            'total' => $recheck->total,
            'counts' => [...self::emptyCounts(), ...($recheck->counts ?? [])],
            'error' => $recheck->error,
            'started_at' => $recheck->created_at?->toIso8601String(),
            'finished_at' => $recheck->finished_at?->toIso8601String(),
            'undone_at' => $recheck->undone_at?->toIso8601String(),
            'by' => $recheck->user?->name,
            'can_undo' => $recheck->canBeUndone(),
            'undo_until' => $recheck->finished_at?->copy()->addDays(LegacyRecheck::UNDO_DAYS)->toIso8601String(),
        ];
    }

    /** @return array<string, int> */
    public static function emptyCounts(): array
    {
        return ['updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'skipped' => 0, 'deleted_cancelled' => 0, 'deleted_to_check' => 0, 'deleted_closed' => 0];
    }

    /** @return array<string, int> */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }

        return $a;
    }

    /** Nettoyage qui regroupe les annulations de suppressions (annulable 7 jours) */
    private function purgeFor(Company $company, ?LegacyRecheck $recheck): LegacyPurge
    {
        $purge = LegacyPurge::create([
            'company_id' => $company->id,
            'user_id' => $recheck?->user_id,
            'min_days' => 0,
            'filters' => ['source' => 'deleted'],
            'status' => 'done',
            'finished_at' => now(),
        ]);
        $recheck?->forceFill(['legacy_purge_id' => $purge->id])->save();

        return $purge;
    }

    private function note(Order $order, string $text): void
    {
        OrderEvent::create([
            'order_id' => $order->id,
            'type' => OrderEventType::Note,
            'actor_type' => 'system',
            'actor_name' => 'Reprise de l\'ancienne application',
            'visible_to_merchant' => false,
            'note' => $text,
        ]);
    }
}
