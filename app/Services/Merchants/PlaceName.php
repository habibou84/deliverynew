<?php

namespace App\Services\Merchants;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Nom d'un lieu d'après sa position (quartier, commune, rue), pour que l'e-commerçant voie tout de suite
 * où son repère est posé. OpenStreetMap (Nominatim) : gratuit, sans clé ; une demande par position,
 * gardée 30 jours. Sans réponse : pas de nom, rien ne casse.
 */
class PlaceName
{
    public const URL = 'https://nominatim.openstreetmap.org/reverse';

    /**
     * @return array{name: string, street: ?string}|null
     */
    public function at(float $lat, float $lng): ?array
    {
        // Même nom pour les positions à une dizaine de mètres près
        $key = sprintf('place-name:%.4f:%.4f', $lat, $lng);

        $found = Cache::get($key);
        if ($found !== null) {
            return $found ?: null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'Jibiat/1.0 (livraison ; +https://jibiat.com)'])
                ->get(self::URL, ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 17, 'addressdetails' => 1, 'accept-language' => 'fr']);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $place = self::fromAddress((array) $response->json('address', []));
        // Gardé, même vide, pour ne pas redemander (les noms ne changent pas)
        Cache::put($key, $place ?? [], now()->addDays(30));

        return $place;
    }

    /**
     * « Riviera 2, Cocody » et la rue, d'après l'adresse renvoyée par OpenStreetMap.
     *
     * @param  array<string, mixed>  $a
     * @return array{name: string, street: ?string}|null
     */
    public static function fromAddress(array $a): ?array
    {
        $first = fn (array $keys) => collect($keys)->map(fn ($k) => $a[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '');
        $area = $first(['neighbourhood', 'quarter', 'suburb', 'residential', 'hamlet', 'village']);
        $town = $first(['city_district', 'town', 'city', 'municipality', 'county']);
        $parts = array_values(array_unique(array_filter([$area, $town])));
        if (! $parts) {
            return null;
        }

        return ['name' => implode(', ', $parts), 'street' => $first(['road', 'pedestrian', 'footway'])];
    }
}
