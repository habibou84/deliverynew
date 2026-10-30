<?php

namespace App\Services\Orders;

use App\Models\Zone;
use App\Services\WhatsApp\ZoneMatcher;
use App\Services\Zones\StandardZones;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Jibiat\Socle\Places\Places;

/**
 * Zone indiquée par son nom (« Cocody », « Yopougon Siporex », « yop ») au lieu de son
 * identifiant : intégrations (boutiques en ligne, API) qui ne connaissent qu'une ville
 * ou un quartier saisis par le client.
 */
class ZoneResolver
{
    public const FIELDS = ['delivery_zone' => 'delivery_zone_id', 'pickup_zone' => 'pickup_zone_id'];

    /** Code d'un lieu de la liste commune (contrat 1.7) */
    public const PLACE_FIELDS = ['delivery_place' => 'delivery_zone_id', 'pickup_place' => 'pickup_zone_id'];

    public function __construct(private readonly ZoneMatcher $matcher, private readonly StandardZones $standard) {}

    /**
     * Complète la requête avec l'identifiant des zones données par leur nom (quand
     * l'identifiant manque). Renvoie les erreurs : champ => message.
     *
     * @return array<string, string>
     */
    public function resolveInto(Request $request, int $companyId): array
    {
        $errors = [];
        $zones = null;

        // Code de lieu : la zone de ce lieu, ou à défaut du lieu qui le contient (quartier, puis commune)
        foreach (self::PLACE_FIELDS as $placeField => $idField) {
            $code = $request->input($placeField);
            if (! is_string($code) || $code === '' || filled($request->input($idField))) {
                continue;
            }
            $zone = $this->standard->zoneForPlace($companyId, $code);
            if ($zone !== null) {
                $request->merge([$idField => $zone->id]);
            } elseif (filled($request->input(array_search($idField, self::FIELDS, true)))) {
                // Lieu non desservi mais nom de zone donné aussi : on essaie le nom
                continue;
            } elseif (Places::find($code) === null) {
                $errors[$placeField] = "Lieu inconnu : « {$code} ».";
            } else {
                $errors[$placeField] = 'Ce lieu n\'est pas desservi : « '.Places::label($code).' ».';
            }
        }

        foreach (self::FIELDS as $nameField => $idField) {
            $name = $request->input($nameField);
            if (! is_string($name) || trim($name) === '' || filled($request->input($idField))) {
                continue;
            }

            $zones ??= Zone::forCompany($companyId)->active()->with('parent')->get();
            $found = $this->match($name, $zones);

            if ($found->count() === 1) {
                $request->merge([$idField => $found->first()->id]);
            } elseif ($found->isEmpty()) {
                $errors[$nameField] = "Zone inconnue : « {$name} ». La liste des zones est donnée par GET /zones.";
            } else {
                $errors[$nameField] = "Zone ambiguë : « {$name} » peut être ".$found->take(5)->map->fullName()->join(', ', ' ou ').'. Précisez le quartier ou indiquez l\'identifiant de zone.';
            }
        }

        return $errors;
    }

    /**
     * Nom exact (« Cocody › Angré », « Angré »), puis zones citées dans le texte, puis début de nom.
     *
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    public function match(string $name, Collection $zones): Collection
    {
        $exact = $zones->filter(fn (Zone $z) => ZoneMatcher::normalize($z->fullName()) === ZoneMatcher::normalize($name));
        if ($exact->count() === 1) {
            return $exact->values();
        }

        $byName = $this->matcher->byName($name, $zones);
        if ($byName && $zones->filter(fn (Zone $z) => ZoneMatcher::normalize($z->name) === ZoneMatcher::normalize($name))->count() === 1) {
            return collect([$byName]);
        }

        // Ancien nom retenu lors d'un rattachement (« YOP SIPOREX »)
        $needle = Places::normalize($name);
        $learned = $zones->filter(fn (Zone $z) => in_array($needle, array_map(Places::normalize(...), $z->aliases ?? []), true));
        if ($learned->count() === 1) {
            return $learned->values();
        }

        // Lieu de la liste commune cité dans le texte (« yop siporex », « 2 plateaux ») : sa zone, ou celle qui le contient
        foreach (Places::inText($name) as $place) {
            foreach (Places::lineage($place['code']) as $code) {
                $zone = $zones->first(fn (Zone $z) => $z->place_code === $code);
                if ($zone !== null) {
                    return collect([$zone]);
                }
            }
        }

        return $this->matcher->answer($name, $zones);
    }
}
