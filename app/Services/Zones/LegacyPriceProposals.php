<?php

namespace App\Services\Zones;

use App\Models\Company;
use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Places\Places;
use RuntimeException;

/**
 * Tarifs proposés : les prix de l'ancienne application (un prix par destination, gardé sur chaque zone reprise),
 * regroupés par montant. Rien ne s'applique sans validation : valider crée un groupe « depuis partout » dans la grille
 * choisie ; ignorer retire la proposition. Les courses déjà créées gardent leur prix.
 */
class LegacyPriceProposals
{
    /**
     * @return array{groups: list<array<string, mixed>>, unlinked: list<array<string, mixed>>}
     */
    public function proposals(Company $company, PricingGrid $grid): array
    {
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_price')
            ->whereNull('legacy_price_done_at')->whereNull('merged_into_id')->with('parent')->orderBy('name')->get();
        $grouped = DB::table('pricing_group_zone')->where('pricing_grid_id', $grid->id)->pluck('zone_id')->flip();

        $line = fn (Zone $z) => [
            'id' => $z->id,
            'old_name' => collect($z->aliases ?? [])->first() ?? $z->name,
            'label' => $z->place_code ? Places::label($z->place_code) : $z->name,
            'linked' => $z->place_code !== null,
            'in_group' => $grouped->has($z->id),
        ];

        // Zones propres à l'entreprise, pas encore rattachées ni gardées telles quelles : à rattacher d'abord
        $unlinked = $zones->filter(fn (Zone $z) => $z->place_code === null && $z->place_checked_at === null);

        return [
            'groups' => $zones->diff($unlinked)->groupBy('legacy_price')->sortKeys()
                ->map(fn ($list, $price) => ['price' => (int) $price, 'zones' => $list->map($line)->values()->all()])->values()->all(),
            'unlinked' => $unlinked->map(fn (Zone $z) => [...$line($z), 'price' => $z->legacy_price])->values()->all(),
        ];
    }

    /**
     * Crée le groupe de prix avec ces zones (celles déjà dans un groupe de la grille n'en changent pas).
     *
     * @param  list<int>  $zoneIds
     */
    public function apply(Company $company, PricingGrid $grid, array $zoneIds, int $price, ?string $name = null): PricingGroup
    {
        $zones = $this->zones($company, $zoneIds);
        if ($zones->isEmpty()) {
            throw new RuntimeException('Aucun lieu à valider.');
        }

        return DB::transaction(function () use ($grid, $zones, $price, $name) {
            $taken = DB::table('pricing_group_zone')->where('pricing_grid_id', $grid->id)->whereIn('zone_id', $zones->pluck('id'))->pluck('zone_id')->flip();
            $group = PricingGroup::create([
                'pricing_grid_id' => $grid->id,
                'name' => $name ?: 'Ancienne application : '.number_format($price, 0, ',', ' ').' F',
                'price' => $price,
                'sort_order' => (int) PricingGroup::where('pricing_grid_id', $grid->id)->max('sort_order') + 1,
            ]);
            foreach ($zones->reject(fn (Zone $z) => $taken->has($z->id)) as $zone) {
                $group->zones()->attach($zone->id, ['pricing_grid_id' => $grid->id]);
            }
            Zone::withoutGlobalScopes()->whereIn('id', $zones->pluck('id'))->update(['legacy_price_done_at' => now()]);

            return $group;
        });
    }

    /**
     * @param  list<int>  $zoneIds
     */
    public function ignore(Company $company, array $zoneIds): int
    {
        return Zone::withoutGlobalScopes()->whereIn('id', $this->zones($company, $zoneIds)->pluck('id'))->update(['legacy_price_done_at' => now()]);
    }

    /**
     * @param  list<int>  $zoneIds
     * @return Collection<int, Zone>
     */
    private function zones(Company $company, array $zoneIds)
    {
        return Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('id', $zoneIds)
            ->whereNotNull('legacy_price')->whereNull('legacy_price_done_at')->get();
    }
}
