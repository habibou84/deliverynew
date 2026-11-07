<?php

namespace App\Services\WhatsApp;

use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Retrouve les zones citées dans un texte libre (« Yop », « Cocody Angré », « yopougon siporex »…).
 */
class ZoneMatcher
{
    /**
     * Zones citées, de la plus précise à la moins précise.
     *
     * @param  Collection<int, Zone>  $zones  zones actives, parents chargés
     * @return Collection<int, Zone>
     */
    public function inText(string $text, Collection $zones): Collection
    {
        $haystack = ' '.self::normalize($text).' ';

        return $zones
            ->filter(fn (Zone $z) => ($name = self::normalize($z->name)) !== '' && str_contains($haystack, " {$name} "))
            // Le quartier l'emporte sur sa commune, le nom le plus long sur le plus court
            ->sortByDesc(fn (Zone $z) => [$z->parent_id !== null ? 1 : 0, mb_strlen($z->name)])
            ->values()
            ->pipe(function (Collection $found) {
                // Un quartier trouvé rend sa commune redondante
                $parents = $found->pluck('parent_id')->filter();

                return $found->reject(fn (Zone $z) => $parents->contains($z->id))->values();
            });
    }

    /**
     * Réponse courte à « Quelle commune ? » : nom exact, puis début de nom (« yop »).
     *
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    public function answer(string $text, Collection $zones): Collection
    {
        $found = $this->inText($text, $zones);
        if ($found->isNotEmpty()) {
            return $found;
        }

        $needle = self::normalize($text);
        if (mb_strlen($needle) < 3) {
            return collect();
        }

        return $zones->filter(fn (Zone $z) => str_starts_with(self::normalize($z->name), $needle)
            || str_contains(self::normalize($z->name), $needle))->values();
    }

    /**
     * Quartier mal écrit (« yopougn sipores », « angre 8em tranche ») : quelques lettres d'écart selon la longueur du
     * nom, les chiffres doivent être les mêmes. Les zones les plus proches d'abord.
     *
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    public function fuzzy(string $text, Collection $zones): Collection
    {
        return $this->scored($text, $zones)->pluck('zone');
    }

    /** @return Collection<int, array{zone: Zone, distance: int}> */
    private function scored(string $text, Collection $zones): Collection
    {
        $words = array_values(array_filter(explode(' ', self::normalize($text)), fn ($w) => $w !== ''));
        if ($words === []) {
            return collect();
        }

        $scored = $zones
            ->map(fn (Zone $z) => ['zone' => $z, 'distance' => self::distanceIn($words, self::normalize($z->name))])
            ->filter(fn ($s) => $s['distance'] !== null);
        // Un quartier dont la commune est aussi citée passe devant les autres
        $ids = $scored->pluck('zone.id');

        return $scored
            ->sortBy(fn ($s) => [$s['distance'], $ids->contains($s['zone']->parent_id) ? 0 : 1, $s['zone']->parent_id !== null ? 0 : 1, -mb_strlen($s['zone']->name)])
            ->pipe(function (Collection $found) {
                $parents = $found->pluck('zone.parent_id')->filter();

                return $found->reject(fn ($s) => $parents->contains($s['zone']->id));
            })
            ->values();
    }

    /**
     * Quartier du client : écrit juste, début de nom (« yop »), puis mal écrit.
     * Une seule zone : c'est elle ; plusieurs : aussi proches l'une que l'autre, le client choisit.
     *
     * @param  Collection<int, Zone>  $zones  zones actives, parents chargés
     * @return Collection<int, Zone>
     */
    public function guess(string $text, Collection $zones, int $max = 3): Collection
    {
        $all = $this->inText($text, $zones);
        $exact = $all->first();
        if ($exact !== null && $exact->parent_id !== null) {
            // Même nom dans deux communes (« Sicogi ») : le client choisit
            return $all->filter(fn (Zone $z) => self::normalize($z->name) === self::normalize($exact->name))->take($max)->values();
        }

        $fuzzy = $this->scored($text, $zones);
        if ($exact !== null) {
            // Commune écrite juste : un de ses quartiers mal écrit la précise
            $fuzzy = $fuzzy->filter(fn ($s) => $s['zone']->parent_id === $exact->id)->values();
            if ($fuzzy->isEmpty()) {
                return collect([$exact]);
            }
        } elseif (($start = $this->answer($text, $zones)->first()) !== null) {
            return collect([$start]);
        }
        if ($fuzzy->isEmpty()) {
            return collect();
        }

        $ids = $fuzzy->pluck('zone.id');
        $rank = fn ($s) => [$s['distance'], $ids->contains($s['zone']->parent_id)];
        $best = $rank($fuzzy->first());

        return $fuzzy->filter(fn ($s) => $rank($s) === $best)->take($max)->pluck('zone')->values();
    }

    /** Deux mots presque pareils (« sipores » / « siporex ») */
    public static function close(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        // « 8em » / « 8e » : un mot avec les mêmes chiffres a droit à une lettre d'écart
        $allowed = max(self::tolerance(strlen($b)), preg_match('/\d/', $b) ? 1 : 0);

        return $a === $b || ($allowed > 0 && preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b)
            && abs(strlen($a) - strlen($b)) <= $allowed && levenshtein($a, $b) <= $allowed);
    }

    /**
     * Plus petit écart entre le nom et une suite de mots du texte (espaces ignorés : « yop ougon », « 8etranche »),
     * null si trop loin.
     *
     * @param  list<string>  $words
     */
    private static function distanceIn(array $words, string $name): ?int
    {
        $compact = str_replace(' ', '', $name);
        $allowed = self::tolerance(strlen($compact));
        if ($allowed === 0) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $compact);
        $size = substr_count($name, ' ') + 1;
        $best = null;
        foreach ([$size - 1, $size, $size + 1] as $n) {
            for ($i = 0; $n >= 1 && $i + $n <= count($words); $i++) {
                $window = implode('', array_slice($words, $i, $n));
                if (abs(strlen($window) - strlen($compact)) > $allowed || preg_replace('/\D/', '', $window) !== $digits) {
                    continue;
                }
                $distance = levenshtein($window, $compact);
                if ($distance <= $allowed && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
        }

        return $best;
    }

    /** Lettres d'écart permises : aucune pour un nom court (« Abo »), puis 1, 2 ou 3 */
    private static function tolerance(int $length): int
    {
        return match (true) {
            $length < 5 => 0,
            $length < 8 => 1,
            $length < 12 => 2,
            default => 3,
        };
    }

    /**
     * Nom de zone renvoyé par l'IA : correspondance exacte (nom ou nom complet).
     *
     * @param  Collection<int, Zone>  $zones
     */
    public function byName(?string $name, Collection $zones): ?Zone
    {
        if (blank($name)) {
            return null;
        }

        $needle = self::normalize($name);

        return $zones->first(fn (Zone $z) => self::normalize($z->fullName()) === $needle)
            ?? $zones->first(fn (Zone $z) => self::normalize($z->name) === $needle);
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
