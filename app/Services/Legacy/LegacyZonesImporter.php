<?php

namespace App\Services\Legacy;

use App\Models\Company;
use App\Models\Zone;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des zones de l'ancienne application (table « fees ») : seulement le nom de chaque lieu, ajouté aux zones de
 * Jibiat. Ni prix ni tarifs. Les noms sont nettoyés (majuscules, prix écrits dans le nom). La ligne d'expédition n'est
 * pas un lieu : elle est ignorée. Une zone déjà présente (même nom) n'est pas recréée : relancer ne crée pas de doublon.
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
        $existing = Zone::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name')
            ->mapWithKeys(fn (string $name) => [ZoneMatcher::normalize($name) => true])->all();
        $counts = ['zones_created' => 0, 'zones_existing' => 0];
        $skipped = [];
        $zones = [];

        DB::transaction(function () use ($company, $rows, $dryRun, &$existing, &$counts, &$skipped, &$zones) {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $raw = trim((string) ($row['destination'] ?? ''));
                if ($raw === '' || preg_match('/exp[ée]dition/iu', $raw)) {
                    $skipped[] = ['type' => 'zone', 'id' => $id, 'name' => $raw, 'reason' => $raw === '' ? 'lieu sans nom' : 'expédition : ce n\'est pas un lieu'];

                    continue;
                }

                $name = $this->clean($raw);
                $key = ZoneMatcher::normalize($name);
                if (isset($existing[$key])) {
                    $counts['zones_existing']++;
                    $zones[] = ['name' => $name, 'status' => 'existing'];

                    continue;
                }

                if (! $dryRun) {
                    $city = trim((string) ($row['city'] ?? ''));
                    Zone::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'city' => $city === '' ? null : $city, 'is_active' => true]);
                }
                $existing[$key] = true;
                $counts['zones_created']++;
                $zones[] = ['name' => $name, 'status' => 'created'];
            }
        });

        return ['counts' => $counts, 'skipped' => $skipped, 'zones' => $zones];
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
