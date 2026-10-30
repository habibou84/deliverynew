<?php

namespace App\Services\Pricing;

use App\Enums\SurchargeType;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Models\PricingRule;
use App\Models\Zone;

/**
 * Calcule le tarif d'une course :
 * 1. grille négociée du marchand, sinon grille par défaut de l'entreprise ;
 * 2. trajet particulier, du plus précis (quartier -> quartier) au plus large (commune -> commune), en remontant
 *    tous les niveaux, dans les deux sens si le prix est symétrique ;
 * 3. sinon prix « depuis partout » du groupe où se trouve le lieu de livraison, ou à défaut le lieu qui le
 *    contient (quartier, puis commune) ;
 * 4. suppléments (express, fragile, poids).
 */
class PricingService
{
    /**
     * @param  array{is_express?: bool, is_fragile?: bool, weight_kg?: float|null}  $options
     */
    public function quote(Merchant $merchant, Zone $pickupZone, Zone $deliveryZone, array $options = []): Quote
    {
        foreach ($this->gridsFor($merchant) as $grid) {
            $rule = $this->findRule($grid, $pickupZone, $deliveryZone);

            if ($rule !== null) {
                return new Quote(
                    basePrice: $rule->price,
                    surcharges: $this->surcharges($grid, $rule->price, $options),
                    pricingGridId: $grid->id,
                    pricingRuleId: $rule->id,
                );
            }

            $group = $this->findGroup($grid, $deliveryZone);
            if ($group !== null) {
                return new Quote(
                    basePrice: $group->price,
                    surcharges: $this->surcharges($grid, $group->price, $options),
                    pricingGridId: $grid->id,
                    pricingRuleId: null,
                    pricingGroupId: $group->id,
                );
            }
        }

        throw new BusinessRuleException(
            "Aucun tarif défini pour le trajet {$pickupZone->name} → {$deliveryZone->name}.",
            'delivery_zone_id',
        );
    }

    /**
     * @return list<PricingGrid>
     */
    private function gridsFor(Merchant $merchant): array
    {
        $grids = [];

        if ($merchant->pricing_grid_id !== null) {
            $grids[] = PricingGrid::forCompany($merchant->company_id)->find($merchant->pricing_grid_id);
        }

        $grids[] = PricingGrid::forCompany($merchant->company_id)->where('is_default', true)->first();

        return array_values(array_filter($grids));
    }

    private function findRule(PricingGrid $grid, Zone $origin, Zone $destination): ?PricingRule
    {
        $origins = $this->lineage($origin);
        $destinations = $this->lineage($destination);

        $rules = PricingRule::where('pricing_grid_id', $grid->id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('origin_zone_id', $origins)->whereIn('destination_zone_id', $destinations))
                ->orWhere(fn ($q) => $q->whereIn('origin_zone_id', $destinations)->whereIn('destination_zone_id', $origins)->where('is_symmetric', true)))
            ->get();

        // Couples du plus précis (quartier -> quartier) au plus large (commune -> commune)
        foreach ($origins as $o) {
            foreach ($destinations as $d) {
                $rule = $rules->first(fn (PricingRule $r) => $r->origin_zone_id === $o && $r->destination_zone_id === $d)
                    ?? $rules->first(fn (PricingRule $r) => $r->is_symmetric && $r->origin_zone_id === $d && $r->destination_zone_id === $o);

                if ($rule !== null) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /** Groupe « depuis partout » du lieu de livraison, ou du premier lieu qui le contient */
    private function findGroup(PricingGrid $grid, Zone $destination): ?PricingGroup
    {
        $lineage = $this->lineage($destination);
        $groups = PricingGroup::query()->where('pricing_groups.pricing_grid_id', $grid->id)
            ->join('pricing_group_zone', 'pricing_group_zone.pricing_group_id', '=', 'pricing_groups.id')
            ->whereIn('pricing_group_zone.zone_id', $lineage)
            ->get(['pricing_groups.*', 'pricing_group_zone.zone_id as matched_zone_id']);

        foreach ($lineage as $zoneId) {
            $group = $groups->first(fn (PricingGroup $g) => (int) $g->matched_zone_id === $zoneId);
            if ($group !== null) {
                return $group;
            }
        }

        return null;
    }

    /**
     * La zone et celles qui la contiennent, du plus précis au plus large.
     *
     * @return list<int>
     */
    private function lineage(Zone $zone): array
    {
        $ids = [$zone->id];
        $parentId = $zone->parent_id;
        // Quatre niveaux au plus : protège d'une boucle dans les zones
        for ($depth = 0; $parentId !== null && $depth < 4 && ! in_array($parentId, $ids, true); $depth++) {
            $ids[] = $parentId;
            $parentId = Zone::withoutGlobalScopes()->whereKey($parentId)->value('parent_id');
        }

        return $ids;
    }

    /**
     * @return list<array{type: string, label: string, amount: int}>
     */
    private function surcharges(PricingGrid $grid, int $basePrice, array $options): array
    {
        $applied = [];
        $weight = $options['weight_kg'] ?? null;

        foreach ($grid->surcharges as $surcharge) {
            $applies = match ($surcharge->type) {
                SurchargeType::Express => (bool) ($options['is_express'] ?? false),
                SurchargeType::Fragile => (bool) ($options['is_fragile'] ?? false),
                SurchargeType::Weight => $weight !== null
                    && $weight >= ($surcharge->min_value ?? 0)
                    && ($surcharge->max_value === null || $weight < $surcharge->max_value),
            };

            if ($applies) {
                $applied[] = [
                    'type' => $surcharge->type->value,
                    'label' => $surcharge->type->label(),
                    'amount' => $surcharge->amountFor($basePrice),
                ];
            }
        }

        return $applied;
    }
}
