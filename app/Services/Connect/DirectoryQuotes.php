<?php

namespace App\Services\Connect;

use App\Enums\CompanyStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Merchant;
use App\Services\Orders\DeliveryDay;
use App\Services\Pricing\PricingService;
use App\Services\Zones\StandardZones;
use Jibiat\Socle\Places\Places;

/**
 * Devis de l'annuaire (contrat socle 1.12) : pour chaque entreprise de l'annuaire, livre-t-elle ce lieu, à quel prix
 * et à partir de quel jour. Le prix est celui de sa grille par défaut, celle que reçoit un nouveau marchand : c'est
 * le prix appliqué après la connexion.
 */
class DirectoryQuotes
{
    public function __construct(
        private readonly StandardZones $zones,
        private readonly PricingService $pricing,
        private readonly DeliveryDay $days,
    ) {}

    /**
     * @param  array{delivery_place: string, pickup_place?: string|null, weight_kg?: float|null, fragile?: bool|null, slugs?: list<string>|null}  $data
     * @return list<array{slug: string, covered: bool, total: int|null, zone: string|null, earliest_date: string|null, message: string|null}>
     */
    public function for(array $data): array
    {
        if (Places::find($data['delivery_place']) === null) {
            return [];
        }

        return Company::query()->where('status', CompanyStatus::Active->value)->where('directory_listed', true)
            ->when(! empty($data['slugs']), fn ($q) => $q->whereIn('slug', $data['slugs']))
            ->orderBy('name')->limit(50)->get()
            ->map(fn (Company $company) => $this->quote($company, $data))
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{slug: string, covered: bool, total: int|null, zone: string|null, earliest_date: string|null, message: string|null}
     */
    private function quote(Company $company, array $data): array
    {
        $none = fn (string $message) => ['slug' => $company->slug, 'covered' => false, 'total' => null, 'zone' => null, 'earliest_date' => null, 'message' => $message];

        $delivery = $this->zones->zoneForPlace($company->id, $data['delivery_place']);
        if ($delivery === null) {
            return $none('Ne livre pas ce lieu.');
        }
        // Lieu de ramassage inconnu ou pas desservi : même zone que la livraison (prix de la zone)
        $pickup = $this->zones->zoneForPlace($company->id, $data['pickup_place'] ?? null) ?? $delivery;

        // Nouveau marchand : ni grille négociée ni niveau Premium
        $merchant = new Merchant;
        $merchant->company_id = $company->id;

        try {
            $quote = $this->pricing->quote($merchant, $pickup, $delivery, [
                'is_fragile' => (bool) ($data['fragile'] ?? false),
                'weight_kg' => isset($data['weight_kg']) ? (float) $data['weight_kg'] : null,
            ]);
        } catch (BusinessRuleException) {
            return $none('Pas de tarif pour ce trajet.');
        }

        $day = $this->days->status($company);

        return [
            'slug' => $company->slug,
            'covered' => true,
            'total' => $quote->total(),
            'zone' => $delivery->fullName(),
            'earliest_date' => $day['closed'] ? $day['next_day'] : $day['today'],
            'message' => null,
        ];
    }
}
