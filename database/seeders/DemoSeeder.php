<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\SurchargeType;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration (environnements local et de test uniquement).
 * Mot de passe de tous les comptes : "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->user(null, Role::SuperAdmin, 'Super administrateur', '0700000000', 'superadmin@livraison.test');

        $company = Company::firstOrCreate(
            ['slug' => 'livraison-express-ci'],
            ['name' => 'Livraison Express CI', 'phone' => '+2252722000000', 'email' => 'contact@livraison.test'],
        );

        app(CompanyProvisioner::class)->provision($company);
        $this->pricing($company);

        $this->user($company, Role::Admin, 'Administrateur', '0700000001', 'admin@livraison.test');
        $this->user($company, Role::Dispatcher, 'Dispatcher', '0700000002', 'dispatch@livraison.test');
        $this->user($company, Role::Cashier, 'Caissier', '0700000003', 'caisse@livraison.test');
        $this->user($company, Role::Courier, 'Koffi Livreur', '0700000004', null);
        $this->user($company, Role::Courier, 'Awa Livreuse', '0700000005', null);

        $cocody = Zone::forCompany($company->id)->where('name', 'Cocody')->first();
        $merchant = Merchant::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'phone' => '+2250500000001'],
            [
                'business_name' => 'Boutique Chic Abidjan',
                'contact_name' => 'Mariam Koné',
                'whatsapp_phone' => '0500000001',
                'pickup_zone_id' => $cocody->id,
                'pickup_address' => 'Riviera 2, rue des Jardins',
                'pickup_landmark' => 'Face pharmacie des Jardins',
            ],
        );
        $this->user($company, Role::MerchantOwner, 'Mariam Koné', '0500000001', 'boutique@livraison.test', $merchant);
    }

    /**
     * Grille par défaut : 1 000 F dans la même commune, 1 500 F entre communes,
     * 2 000 F vers ou depuis la périphérie (Anyama, Bingerville, Songon).
     */
    private function pricing(Company $company): void
    {
        $grid = PricingGrid::forCompany($company->id)->where('is_default', true)->first();
        $zones = Zone::forCompany($company->id)->whereNull('parent_id')->get();
        $outskirts = ['Anyama', 'Bingerville', 'Songon'];

        foreach ($zones as $origin) {
            foreach ($zones as $destination) {
                if ($origin->id > $destination->id) {
                    continue; // règles symétriques : une seule par paire
                }

                $price = match (true) {
                    $origin->id === $destination->id => 1000,
                    in_array($origin->name, $outskirts) || in_array($destination->name, $outskirts) => 2000,
                    default => 1500,
                };

                PricingRule::updateOrCreate(
                    ['pricing_grid_id' => $grid->id, 'origin_zone_id' => $origin->id, 'destination_zone_id' => $destination->id],
                    ['price' => $price, 'is_symmetric' => true],
                );
            }
        }

        $grid->surcharges()->firstOrCreate(['type' => SurchargeType::Express], ['amount' => 1000]);
        $grid->surcharges()->firstOrCreate(['type' => SurchargeType::Weight, 'min_value' => 10], ['amount' => 1000]);
    }

    private function user(?Company $company, Role $role, string $name, string $phone, ?string $email, ?Merchant $merchant = null): void
    {
        $user = User::where('phone', '+225'.$phone)->first() ?? User::create([
            'company_id' => $company?->id,
            'merchant_id' => $merchant?->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'password' => 'password',
        ]);

        $user->syncRoles([$role->value]);
        $user->syncCourierProfile();
    }
}
