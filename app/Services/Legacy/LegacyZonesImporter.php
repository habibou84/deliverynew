<?php

namespace App\Services\Legacy;

use App\Models\Company;
use App\Models\Zone;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Places\Places;

/**
 * Reprise des zones de l'ancienne application (table « fees ») : les noms sont nettoyés (majuscules, prix écrits dans
 * le nom). Un nom qui est exactement un lieu de la liste commune (nom ou variante : « YOP », « Riv 3 ») reprend la
 * zone standard de ce lieu ; sinon une zone déjà présente du même nom ; sinon une zone propre à l'entreprise est
 * créée, à rattacher plus tard. Le prix de l'ancienne application est gardé sur la zone comme proposition (rien ne
 * s'applique sans validation, dans « Tarifs proposés »). La ligne d'expédition n'est pas un lieu : elle est ignorée.
 * Relancer ne crée pas de doublon et met les prix à jour.
 */
class LegacyZonesImporter
{
    public function import(Company $company, SqlDumpReader $dump, bool $dryRun = false): array
    {
        return $this->importRows($company, $dump->hasTable('fees') ? iterator_to_array($dump->rows('fees'), false) : [], $dryRun);
    }

    /**
     * @param  list<array<string, string|null>>  $rows  lignes de la table « fees »
     * @return array{counts: array{zones_created: int, zones_existing: int}, skipped: list<array<string, string>>, zones: list<array{name: string, status: string}>}
     */
    public function importRows(Company $company, array $rows, bool $dryRun = false): array
    {
        $all = Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('merged_into_id')->get();
        $existing = $all->keyBy(fn (Zone $z) => ZoneMatcher::normalize($z->name))->all();
        $byPlace = $all->whereNotNull('place_code')->keyBy('place_code');
        $counts = ['zones_created' => 0, 'zones_existing' => 0];
        $skipped = [];
        $zones = [];

        DB::transaction(function () use ($company, $rows, $dryRun, $byPlace, &$existing, &$counts, &$skipped, &$zones) {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $raw = trim((string) ($row['destination'] ?? ''));
                if ($raw === '' || preg_match('/exp[ée]dition/iu', $raw)) {
                    $skipped[] = ['type' => 'zone', 'id' => $id, 'name' => $raw, 'reason' => $raw === '' ? 'lieu sans nom' : 'expédition : ce n\'est pas un lieu'];

                    continue;
                }

                $name = $this->clean($raw);
                $key = ZoneMatcher::normalize($name);
                $price = is_numeric($row['price'] ?? null) && (int) $row['price'] > 0 ? (int) $row['price'] : null;
                $place = Places::named($name);
                $zone = ($place ? $byPlace->get($place['code']) : null) ?? ($existing[$key] ?? null);
                if ($zone !== null) {
                    if (! $dryRun) {
                        $this->remember($zone, $name, $price);
                    }
                    $counts['zones_existing']++;
                    $zones[] = ['name' => $name, 'status' => 'existing'];

                    continue;
                }

                if (! $dryRun) {
                    // Ville absente dans l'ancienne application : la ville par défaut des zones (Abidjan)
                    $city = trim((string) ($row['city'] ?? ''));
                    $existing[$key] = Zone::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'is_active' => true, 'legacy_price' => $price]
                        + ($city === '' ? [] : ['city' => $city]));
                } else {
                    $existing[$key] = new Zone(['name' => $name]);
                }
                $counts['zones_created']++;
                $zones[] = ['name' => $name, 'status' => 'created'];
            }
        });

        return ['counts' => $counts, 'skipped' => $skipped, 'zones' => $zones];
    }

    /** Ancien nom (s'il diffère) retenu comme variante, et ancien prix gardé comme proposition */
    private function remember(Zone $zone, string $name, ?int $price): void
    {
        if (! $zone->exists) {
            return;
        }
        $aliases = $zone->aliases ?? [];
        if (ZoneMatcher::normalize($name) !== ZoneMatcher::normalize($zone->name) && ! in_array($name, $aliases, true)) {
            $aliases[] = $name;
        }
        $zone->forceFill(['aliases' => $aliases ?: null] + ($price !== null ? ['legacy_price' => $price] : []))->save();
    }

    /**
     * « SONGON(Après Brimbresso 2500F) » → « Songon (Après Brimbresso) » ; « ABOBO  N'DOTRE » → « Abobo N'Dotre ».
     */
    private function clean(string $name): string
    {
        // Prix écrits dans le nom
        $name = preg_replace('/\s*\d[\d\s.]*\s*f(cfa)?\b/iu', '', $name);
        $name = preg_replace('/\(\s*/u', ' (', $name);
        $name = preg_replace('/\s*\)/u', ')', $name);
        $name = preg_replace('/\s*\(\)/u', '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        // Mots tout en majuscules : une majuscule au début (et après une apostrophe)
        $name = preg_replace_callback("/[\p{L}']{2,}/u", function ($m) {
            $word = $m[0];
            if (mb_strtoupper($word) !== $word) {
                return $word;
            }
            $word = mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);

            return preg_replace_callback("/'(\p{Ll})/u", fn ($a) => "'".mb_strtoupper($a[1]), $word);
        }, $name);

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
