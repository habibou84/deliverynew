<?php

namespace App\Services\Zones;

use App\Models\Company;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Jibiat\Socle\Places\Places;

/**
 * Zones standard d'une entreprise : une zone par lieu de la liste commune du socle (communes et quartiers d'Abidjan,
 * villes de l'intérieur), rangée sous sa commune et portant le code du lieu. L'entreprise ne crée plus ses zones :
 * elle fixe ses prix et masque les lieux où elle ne livre pas. Les villes de l'intérieur arrivent masquées.
 *
 * Une zone qui existe déjà avec le même nom, au bon endroit (une commune, ou un quartier sous sa commune), est
 * rattachée à son lieu. Un quartier repris sans commune (« Angré » au premier niveau) n'est pas touché : il est
 * proposé dans « Rattacher les zones », et son lieu n'est pas recréé en double. Relancer ne crée pas de doublon.
 */
class StandardZones
{
    public function syncAll(): void
    {
        Company::query()->select('id')->eachById(fn (Company $company) => $this->sync($company));
    }

    /**
     * @return array{created: int, linked: int}
     */
    public function sync(Company $company): array
    {
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $byCode = $zones->whereNotNull('place_code')->keyBy('place_code');
        $counts = ['created' => 0, 'linked' => 0];
        $sort = (int) $zones->max('sort_order');

        foreach (Places::all() as $place) {
            // Abidjan n'est pas une zone : ses communes sont les zones du premier niveau
            if ($place['code'] === 'abidjan' || $byCode->has($place['code'])) {
                continue;
            }
            $parent = $place['type'] === Places::NEIGHBORHOOD ? $byCode->get($place['parent']) : null;
            if ($place['type'] === Places::NEIGHBORHOOD && $parent === null) {
                continue;
            }

            $sameName = $this->sameName($zones, $place['name']);
            $inPlace = $sameName->first(fn (Zone $z) => $z->place_code === null && $z->parent_id === $parent?->id && $z->merged_into_id === null);
            if ($inPlace !== null) {
                $inPlace->forceFill(['place_code' => $place['code']])->save();
                $byCode->put($place['code'], $inPlace);
                $counts['linked']++;

                continue;
            }
            // Même nom ailleurs (quartier repris sans sa commune) : à rattacher à la main, pas de doublon
            if ($sameName->contains(fn (Zone $z) => $z->place_code === null && $z->merged_into_id === null)) {
                continue;
            }

            $zone = Zone::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'parent_id' => $parent?->id,
                'place_code' => $place['code'],
                'name' => $place['name'],
                'city' => $this->city($place),
                'is_active' => $place['type'] !== Places::CITY,
                'sort_order' => ++$sort,
            ]);
            $zones->push($zone);
            $byCode->put($place['code'], $zone);
            $counts['created']++;
        }

        return $counts;
    }

    /**
     * Zone de l'entreprise pour un lieu, ou à défaut pour le lieu qui le contient (quartier, puis commune).
     */
    public function zoneForPlace(int $companyId, ?string $code, bool $activeOnly = true): ?Zone
    {
        if ($code === null || Places::find($code) === null) {
            return null;
        }
        $lineage = Places::lineage($code);
        $zones = Zone::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('place_code', $lineage)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))->get()->keyBy('place_code');

        foreach ($lineage as $candidate) {
            if ($zones->has($candidate)) {
                return $zones->get($candidate);
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    private function sameName(Collection $zones, string $name): Collection
    {
        $needle = Places::normalize($name);

        return $zones->filter(fn (Zone $z) => Places::normalize($z->name) === $needle);
    }

    private function city(array $place): string
    {
        return str_starts_with($place['code'], 'abidjan') ? 'Abidjan' : $place['name'];
    }
}
