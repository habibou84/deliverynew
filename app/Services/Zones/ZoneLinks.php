<?php

namespace App\Services\Zones;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneLink;
use App\Services\Legacy\LegacyOrdersImporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Places\Places;
use RuntimeException;

/**
 * Rattacher les zones propres à l'entreprise (reprises de l'ancienne application, créées à la main) aux lieux de la
 * liste commune. Rattacher : la zone prend le lieu, se range sous sa commune et garde son ancien nom comme variante.
 * Fusionner (le lieu a déjà sa zone) : tout ce qui pointait vers l'ancienne zone passe sur la zone du lieu (courses,
 * e-commerçants, destinataires, points relais, prix, planning, paie), l'ancienne zone est masquée. Rien n'est envoyé
 * à personne ; annulable pendant 7 jours.
 */
class ZoneLinks
{
    /** Colonnes qui désignent une zone : table => colonnes */
    private const REFERENCES = [
        'orders' => ['pickup_zone_id', 'delivery_zone_id'],
        'merchants' => ['pickup_zone_id'],
        'recipients' => ['zone_id'],
        'hubs' => ['zone_id'],
        'connect_requests' => ['pickup_zone_id'],
    ];

    public function __construct(private readonly StandardZones $standard) {}

    /**
     * Zones à rattacher, avec le lieu proposé : « sure » (nom ou variante exacte), « confirm » (lieu cité dans le
     * nom), « none » (aucun lieu trouvé).
     *
     * @return list<array<string, mixed>>
     */
    public function proposals(Company $company): array
    {
        $zones = $this->pending($company);
        $byPlace = Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('place_code')
            ->whereNull('merged_into_id')->get()->keyBy('place_code');
        $orders = $this->orderCounts($company, $zones->pluck('id')->all());

        return $zones->map(function (Zone $zone) use ($byPlace, $orders) {
            [$place, $confidence] = $this->propose($zone);
            $target = $place ? $byPlace->get($place['code']) : null;

            return [
                'id' => $zone->id,
                'name' => $zone->name,
                'parent' => $zone->parent?->name,
                'orders' => $orders[$zone->id] ?? 0,
                'legacy_price' => $zone->legacy_price,
                'confidence' => $confidence,
                'place' => $place ? ['code' => $place['code'], 'label' => Places::label($place['code']), 'type' => $place['type']] : null,
                // Le lieu a déjà sa zone : les deux seront fusionnées
                'merge_into' => $target && $target->id !== $zone->id ? ['id' => $target->id, 'name' => $target->name] : null,
            ];
        })->sortBy(fn ($p) => [['sure' => 0, 'confirm' => 1, 'none' => 2][$p['confidence']], -$p['orders']])->values()->all();
    }

    /** Rattache la zone au lieu, ou la fusionne dans la zone qui a déjà ce lieu */
    public function link(Zone $zone, string $code, ?User $by): ZoneLink
    {
        $place = Places::find($code) ?? throw new RuntimeException('Lieu inconnu.');
        if ($zone->place_code !== null || $zone->merged_into_id !== null) {
            throw new RuntimeException('Cette zone est déjà rattachée.');
        }
        $company = Company::findOrFail($zone->company_id);
        // Le lieu (ou sa commune) doit exister chez l'entreprise : on complète les zones standard au besoin
        $this->standard->sync($company);
        $target = Zone::withoutGlobalScopes()->where('company_id', $zone->company_id)->where('place_code', $code)
            ->whereNull('merged_into_id')->first();

        return $target !== null && $target->id !== $zone->id
            ? $this->merge($zone, $target, $by)
            : $this->attach($zone, $place, $by);
    }

    /** Garder telle quelle : zone propre à l'entreprise, plus proposée */
    public function keep(Zone $zone): void
    {
        $zone->forceFill(['place_checked_at' => now()])->save();
    }

    /**
     * Rattache toutes les propositions sûres.
     *
     * @return int nombre de zones rattachées
     */
    public function linkSure(Company $company, ?User $by): int
    {
        $count = 0;
        foreach ($this->proposals($company) as $proposal) {
            if ($proposal['confidence'] === 'sure') {
                $this->link(Zone::withoutGlobalScopes()->findOrFail($proposal['id']), $proposal['place']['code'], $by);
                $count++;
            }
        }

        return $count;
    }

    public function undo(ZoneLink $link): void
    {
        if (! $link->canBeUndone()) {
            throw new RuntimeException('Ce rattachement ne peut plus être annulé.');
        }

        DB::transaction(function () use ($link) {
            $before = $link->before;
            $zone = Zone::withoutGlobalScopes()->findOrFail($link->zone_id);
            $zone->forceFill($before['zone'])->save();

            foreach ($before['moved'] ?? [] as $table => $columns) {
                foreach ($columns as $column => $ids) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->update([$column => $zone->id]);
                    }
                }
            }
            foreach ($before['children'] ?? [] as $childId) {
                Zone::withoutGlobalScopes()->whereKey($childId)->update(['parent_id' => $zone->id]);
            }
            foreach ($before['rules'] ?? [] as $ruleId => $cols) {
                DB::table('pricing_rules')->where('id', $ruleId)->update($cols);
            }
            foreach ($before['groups'] ?? [] as $row) {
                DB::table('pricing_group_zone')->insertOrIgnore($row);
            }
            foreach ($before['couriers'] ?? [] as $courierId) {
                DB::table('courier_zone')->insertOrIgnore(['courier_id' => $courierId, 'zone_id' => $zone->id]);
            }
            foreach ($before['json'] ?? [] as $table => $rows) {
                foreach ($rows as $id => $cols) {
                    DB::table($table)->where('id', $id)->update($cols);
                }
            }
            if ($link->into_zone_id && isset($before['into'])) {
                Zone::withoutGlobalScopes()->whereKey($link->into_zone_id)->update($before['into']);
            }
            $link->forceFill(['status' => 'undone', 'undone_at' => now()])->save();
        });
    }

    /**
     * Courses dont la zone est « À préciser » : la commune écrite dans l'ancienne application est relue et cherchée
     * dans les zones de l'entreprise (noms, anciens noms retenus, lieux de la liste commune). Seule la zone change :
     * ni prix, ni statut, ni message.
     *
     * @param  callable(list<string>): array<string, array<string, mixed>>  $fetch  commandes de l'ancienne application par numéro
     * @return array{checked: int, reclassed: int, left: int}
     */
    public function reclass(Company $company, LegacyOrdersImporter $importer, callable $fetch, int $limit = 300): array
    {
        $unknown = Zone::withoutGlobalScopes()->where('company_id', $company->id)->where('name', LegacyOrdersImporter::UNKNOWN_ZONE)->first();
        if ($unknown === null) {
            return ['checked' => 0, 'reclassed' => 0, 'left' => 0];
        }
        $query = fn () => Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id')
            ->where(fn ($q) => $q->where('delivery_zone_id', $unknown->id)->orWhere('pickup_zone_id', $unknown->id));

        $orders = $query()->orderByDesc('id')->limit($limit)->get(['id', 'legacy_id', 'delivery_zone_id', 'pickup_zone_id']);
        $importer->for($company);
        $reclassed = 0;
        foreach ($orders->chunk(100) as $chunk) {
            $commands = $fetch($chunk->pluck('legacy_id')->map(fn ($id) => (string) $id)->all());
            foreach ($chunk as $order) {
                $command = $commands[(string) $order->legacy_id] ?? null;
                if ($command === null) {
                    continue;
                }
                $changes = [];
                if ($order->delivery_zone_id === $unknown->id && ($zone = $importer->zoneNamed($command['commune'] ?? null))) {
                    $changes['delivery_zone_id'] = $zone->id;
                }
                if ($order->pickup_zone_id === $unknown->id && ($zone = $importer->zoneNamed($command['ram_commune'] ?? null))) {
                    $changes['pickup_zone_id'] = $zone->id;
                }
                if ($changes !== []) {
                    Order::withoutGlobalScopes()->whereKey($order->id)->update($changes);
                    $reclassed++;
                }
            }
        }

        return ['checked' => $orders->count(), 'reclassed' => $reclassed, 'left' => $query()->count()];
    }

    /** Nombre de courses « À préciser » */
    public function unknownCount(Company $company): int
    {
        $unknown = Zone::withoutGlobalScopes()->where('company_id', $company->id)->where('name', LegacyOrdersImporter::UNKNOWN_ZONE)->value('id');

        return $unknown === null ? 0 : Order::withoutGlobalScopes()->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('delivery_zone_id', $unknown)->orWhere('pickup_zone_id', $unknown))->count();
    }

    /**
     * @return Collection<int, Zone>
     */
    public function pending(Company $company): Collection
    {
        return Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('place_code')
            ->whereNull('merged_into_id')->whereNull('place_checked_at')->where('name', '!=', LegacyOrdersImporter::UNKNOWN_ZONE)
            // Zones d'expédition (gare, transporteur) : propres à l'entreprise par nature
            ->where('is_shipping', false)
            ->with('parent')->orderBy('name')->get();
    }

    /**
     * @return array{0: ?array, 1: string}
     */
    private function propose(Zone $zone): array
    {
        $names = array_merge([$zone->name], $zone->aliases ?? []);
        foreach ($names as $name) {
            if ($place = Places::named($name)) {
                return [$place, 'sure'];
            }
        }
        $found = Places::inText($zone->parent ? $zone->parent->name.' '.$zone->name : $zone->name);

        return $found !== [] ? [$found[0], 'confirm'] : [null, 'none'];
    }

    private function attach(Zone $zone, array $place, ?User $by): ZoneLink
    {
        return DB::transaction(function () use ($zone, $place, $by) {
            $parent = $place['type'] === Places::NEIGHBORHOOD
                ? Zone::withoutGlobalScopes()->where('company_id', $zone->company_id)->where('place_code', $place['parent'])->whereNull('merged_into_id')->first()
                : null;
            $before = ['zone' => $zone->only(['name', 'parent_id', 'place_code', 'aliases', 'place_checked_at'])];

            $zone->forceFill([
                'place_code' => $place['code'],
                'parent_id' => $parent?->id,
                'name' => $place['name'],
                'aliases' => $this->withAlias($zone->aliases, $zone->name, $place['name']),
                'place_checked_at' => now(),
            ])->save();

            return ZoneLink::create(['company_id' => $zone->company_id, 'user_id' => $by?->id, 'zone_id' => $zone->id,
                'action' => 'link', 'place_code' => $place['code'], 'before' => $before]);
        });
    }

    private function merge(Zone $zone, Zone $into, ?User $by): ZoneLink
    {
        return DB::transaction(function () use ($zone, $into, $by) {
            $before = [
                'zone' => $zone->only(['is_active', 'merged_into_id', 'place_checked_at']),
                'into' => $into->only(['aliases', 'legacy_price']),
                'moved' => [],
            ];

            foreach (self::REFERENCES as $table => $columns) {
                foreach ($columns as $column) {
                    $ids = DB::table($table)->where($column, $zone->id)->pluck('id')->all();
                    if ($ids !== []) {
                        $before['moved'][$table][$column] = $ids;
                        foreach (array_chunk($ids, 500) as $chunk) {
                            DB::table($table)->whereIn('id', $chunk)->update([$column => $into->id]);
                        }
                    }
                }
            }

            // Quartiers rangés sous l'ancienne zone
            $before['children'] = Zone::withoutGlobalScopes()->where('parent_id', $zone->id)->pluck('id')->all();
            Zone::withoutGlobalScopes()->where('parent_id', $zone->id)->update(['parent_id' => $into->id]);

            // Prix des trajets : passés sur la zone du lieu, sauf si elle a déjà un prix pour ce trajet
            foreach (DB::table('pricing_rules')->where('origin_zone_id', $zone->id)->orWhere('destination_zone_id', $zone->id)->get() as $rule) {
                $origin = $rule->origin_zone_id === $zone->id ? $into->id : $rule->origin_zone_id;
                $destination = $rule->destination_zone_id === $zone->id ? $into->id : $rule->destination_zone_id;
                $taken = DB::table('pricing_rules')->where('pricing_grid_id', $rule->pricing_grid_id)
                    ->where('origin_zone_id', $origin)->where('destination_zone_id', $destination)->exists();
                if (! $taken) {
                    $before['rules'][$rule->id] = ['origin_zone_id' => $rule->origin_zone_id, 'destination_zone_id' => $rule->destination_zone_id];
                    DB::table('pricing_rules')->where('id', $rule->id)->update(['origin_zone_id' => $origin, 'destination_zone_id' => $destination]);
                }
            }

            // Groupes de prix : la zone du lieu prend la place de l'ancienne, si elle n'est pas déjà dans un groupe
            foreach (DB::table('pricing_group_zone')->where('zone_id', $zone->id)->get() as $row) {
                $before['groups'][] = (array) $row;
                DB::table('pricing_group_zone')->where('pricing_group_id', $row->pricing_group_id)->where('zone_id', $zone->id)->delete();
                DB::table('pricing_group_zone')->insertOrIgnore(['pricing_group_id' => $row->pricing_group_id, 'zone_id' => $into->id, 'pricing_grid_id' => $row->pricing_grid_id]);
            }

            // Livreurs affectés à la zone
            $before['couriers'] = DB::table('courier_zone')->where('zone_id', $zone->id)->pluck('courier_id')->all();
            foreach ($before['couriers'] as $courierId) {
                DB::table('courier_zone')->insertOrIgnore(['courier_id' => $courierId, 'zone_id' => $into->id]);
            }
            DB::table('courier_zone')->where('zone_id', $zone->id)->delete();

            // Planning (zone_ids) et paie (zone_amounts) : identifiants rangés en JSON
            $before['json'] = $this->rewriteJson($zone->company_id, $zone->id, $into->id);

            $into->forceFill([
                'aliases' => $this->withAlias($into->aliases, $zone->name, $into->name),
                'legacy_price' => $into->legacy_price ?? $zone->legacy_price,
            ])->save();
            $zone->forceFill(['is_active' => false, 'merged_into_id' => $into->id, 'place_checked_at' => now()])->save();

            return ZoneLink::create(['company_id' => $zone->company_id, 'user_id' => $by?->id, 'zone_id' => $zone->id,
                'into_zone_id' => $into->id, 'action' => 'merge', 'place_code' => $into->place_code, 'before' => $before]);
        });
    }

    /**
     * @return array<string, array<int, array<string, string|null>>>
     */
    private function rewriteJson(int $companyId, int $from, int $to): array
    {
        $before = [];
        foreach (DB::table('courier_rules')->where('company_id', $companyId)->whereNotNull('zone_ids')->get(['id', 'zone_ids']) as $row) {
            $ids = json_decode((string) $row->zone_ids, true) ?: [];
            if (in_array($from, array_map('intval', $ids), true)) {
                $before['courier_rules'][$row->id] = ['zone_ids' => $row->zone_ids];
                $new = array_values(array_unique(array_map(fn ($id) => (int) $id === $from ? $to : (int) $id, $ids)));
                DB::table('courier_rules')->where('id', $row->id)->update(['zone_ids' => json_encode($new)]);
            }
        }
        $plans = DB::table('pay_plans')->where('company_id', $companyId)->pluck('id');
        foreach (DB::table('pay_plan_rules')->whereIn('pay_plan_id', $plans)->whereNotNull('zone_amounts')->get(['id', 'zone_amounts']) as $row) {
            $amounts = json_decode((string) $row->zone_amounts, true) ?: [];
            if (array_key_exists((string) $from, $amounts)) {
                $before['pay_plan_rules'][$row->id] = ['zone_amounts' => $row->zone_amounts];
                $amounts[(string) $to] ??= $amounts[(string) $from];
                unset($amounts[(string) $from]);
                DB::table('pay_plan_rules')->where('id', $row->id)->update(['zone_amounts' => json_encode($amounts)]);
            }
        }

        return $before;
    }

    /**
     * @return array<int, int>
     */
    private function orderCounts(Company $company, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }
        $counts = [];
        foreach (['delivery_zone_id', 'pickup_zone_id'] as $column) {
            Order::withoutGlobalScopes()->where('company_id', $company->id)->whereIn($column, $zoneIds)
                ->selectRaw("{$column} as zone_id, COUNT(*) as n")->groupBy($column)->get()
                ->each(function ($row) use (&$counts) {
                    $counts[(int) $row->zone_id] = ($counts[(int) $row->zone_id] ?? 0) + (int) $row->n;
                });
        }

        return $counts;
    }

    /**
     * @return list<string>|null
     */
    private function withAlias(?array $aliases, string $old, string $new): ?array
    {
        $aliases ??= [];
        if (Places::normalize($old) !== Places::normalize($new) && ! in_array($old, $aliases, true)) {
            $aliases[] = $old;
        }

        return $aliases ?: null;
    }
}
