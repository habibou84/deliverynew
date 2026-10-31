<?php

namespace App\Console\Commands;

use App\Enums\FeePayer;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierAbsence;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\CompanyProvisioner;
use App\Services\Orders\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Entreprise de démonstration « tout automatique », pour filmer la vidéo de l'automatisation : livreurs avec zones,
 * véhicule, capacité et fin de service ; planning des ramassages et des livraisons avec remplaçants ; une absence ;
 * réglages de l'automatisation complète. --courses=N crée N courses comme les e-commerçants, pour voir sur place
 * la validation et l'assignation se faire seules. Jamais en production (mot de passe de démonstration « password »).
 */
class DemoAutomation extends Command
{
    public const SLUG = 'auto-demo';

    private const PASSWORD = 'password';

    protected $signature = 'demo:automation {--courses=0 : Courses à créer comme les e-commerçants (0 à 30)}';

    protected $description = 'Crée ou remet en place l\'entreprise de démonstration de l\'automatisation (préproduction)';

    /** Livreurs : nom, téléphone, véhicule, zones principales, zones de secours, colis max, argent max, fin de service */
    private const COURIERS = [
        ['Awa Koné', '0711000001', 'moto', ['Cocody'], ['Plateau'], 15, 300000, '19:00'],
        ['Yacouba Traoré', '0711000002', 'moto', ['Yopougon'], ['Attécoubé'], 15, 300000, '19:00'],
        ['Serge Kouassi', '0711000003', 'moto', ['Marcory', 'Treichville'], ['Koumassi'], 15, 300000, '19:00'],
        ['Fatou Bamba', '0711000004', 'moto', ['Plateau', 'Adjamé'], ['Cocody'], 12, 250000, '18:30'],
        ['Ibrahim Diallo', '0711000005', 'tricycle', ['Abobo', 'Anyama'], ['Adjamé'], 25, 400000, '19:00'],
        ['Koffi Yao', '0711000006', 'moto', ['Koumassi', 'Port-Bouët'], ['Marcory'], 15, 300000, '19:00'],
    ];

    /** E-commerçants : boutique, contact, téléphone, zone de ramassage, adresse */
    private const MERCHANTS = [
        ['Boutique Chic Abidjan', 'Mariam Koné', '0511000001', 'Cocody', 'Riviera 2, rue des Jardins'],
        ['Saveurs d\'Ivoire', 'Aminata Touré', '0511000002', 'Yopougon', 'Siporex, face au marché'],
        ['Tech Store CI', 'Jean Kouadio', '0511000003', 'Marcory', 'Zone 4, rue du Canal'],
        ['Beauté Divine', 'Grâce N\'Guessan', '0511000004', 'Plateau', 'Avenue Chardy'],
        ['Mode Abobo', 'Salif Ouattara', '0511000005', 'Abobo', 'Abobo Gare, près de la mairie'],
    ];

    public function handle(OrderService $orders): int
    {
        if (app()->isProduction()) {
            $this->error('Réservé à la préproduction et aux postes de développement : jamais en production.');

            return self::FAILURE;
        }

        $company = Company::firstOrCreate(['slug' => self::SLUG], [
            'name' => 'Express Auto Démo',
            'phone' => '+2252722110000',
            'email' => 'contact@auto-demo.test',
            'timezone' => 'Africa/Abidjan',
        ]);
        app(CompanyProvisioner::class)->provision($company);

        $this->settings($company);
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('parent_id')->pluck('id', 'name');
        $this->pricing($company, $zones);

        $this->user($company, Role::Admin, 'Responsable Démo', '0711000010');
        $this->user($company, Role::Cashier, 'Caisse Démo', '0711000011');
        $couriers = $this->couriers($company, $zones);
        $merchants = $this->merchants($company, $zones);
        $this->planning($company, $zones, $couriers, $merchants);

        $count = max(0, min(30, (int) $this->option('courses')));
        if ($count > 0) {
            $this->orders($orders, $merchants, $zones, $count);
        }

        $this->info("Entreprise « {$company->name} » prête (adresse : ".self::SLUG.'.<domaine>).');
        $this->table(['Compte', 'Identifiant', 'Mot de passe'], [
            ['Administrateur (/admin)', '0711000010', self::PASSWORD],
            ['Caisse (/admin)', '0711000011', self::PASSWORD],
            ...collect(self::COURIERS)->map(fn ($c) => ["Livreur {$c[0]} (/livreur)", $c[1], self::PASSWORD]),
            ...collect(self::MERCHANTS)->map(fn ($m) => ["E-commerçant {$m[0]} (/)", $m[2], self::PASSWORD]),
        ]);

        return self::SUCCESS;
    }

    /** Réglages de l'automatisation complète */
    private function settings(Company $company): void
    {
        $company->forceFill([
            'status' => 'active',
            'trial_ends_at' => $company->trial_ends_at ?? now()->addDays(60),
            // Courses validées sans l'équipe
            'validation_mode' => Company::VALIDATION_AUTO,
            // Ramassages selon le planning, livraisons du jour même confiées toutes seules
            'auto_pickup' => Company::AUTO_ON,
            'auto_delivery' => Company::AUTO_ON,
            'auto_delivery_max_cod' => 150000,
            'same_courier_delivery' => false,
            // Missions acceptées d'office (empêchement possible), livreur muet 20 min : la mission passe à un autre
            'mission_acceptance' => Company::ACCEPT_REPORT,
            'unreachable_minutes' => 20,
            'late_pickup_alert_minutes' => 60,
            'courier_available_alert' => true,
            // Journée : heure limite (plus tôt le samedi), fermeture du dépôt, rappel de clôture de caisse
            'daily_cutoff_time' => '16:00',
            'cutoff_by_day' => ['6' => '12:00'],
            'depot_closes_at' => '19:00',
            'cash_close_reminder_at' => '19:30',
            // Colis gardé par le ramasseur jusqu'à sa livraison ; relais avec code entre deux livreurs
            'pickup_mission_custody' => Company::CUSTODY_KEEP,
            'relay_required' => true,
            // Destinataires prévenus, code de livraison, avis demandé, actions hors connexion
            'notify_recipients' => true,
            'require_delivery_code' => true,
            'ask_recipient_rating' => true,
            'offline_courier_actions' => true,
        ])->save();
    }

    /**
     * Grille simple : 1 000 F dans la même commune, 1 500 F entre communes.
     *
     * @param  Collection<string, int>  $zones
     */
    private function pricing(Company $company, Collection $zones): void
    {
        $grid = PricingGrid::withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->first();
        $ids = $zones->only(['Abobo', 'Adjamé', 'Anyama', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Treichville', 'Yopougon'])->values();

        foreach ($ids as $origin) {
            foreach ($ids as $destination) {
                if ($origin > $destination) {
                    continue;
                }
                PricingRule::updateOrCreate(
                    ['pricing_grid_id' => $grid->id, 'origin_zone_id' => $origin, 'destination_zone_id' => $destination],
                    ['price' => $origin === $destination ? 1000 : 1500, 'is_symmetric' => true],
                );
            }
        }
    }

    /**
     * @param  Collection<string, int>  $zones
     * @return Collection<string, Courier> par nom
     */
    private function couriers(Company $company, Collection $zones): Collection
    {
        return collect(self::COURIERS)->mapWithKeys(function (array $c) use ($company, $zones) {
            [$name, $phone, $vehicle, $main, $backup, $maxParcels, $maxCash, $shiftEnd] = $c;
            $user = $this->user($company, Role::Courier, $name, $phone);
            $courier = Courier::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
            $courier->forceFill([
                'vehicle_type' => $vehicle,
                'max_parcels' => $maxParcels,
                'max_cash' => $maxCash,
                'shift_ends_at' => $shiftEnd,
                'is_available' => true,
            ])->save();
            $courier->zones()->sync(
                collect($main)->mapWithKeys(fn ($z) => [$zones[$z] => ['is_backup' => false]])
                    ->union(collect($backup)->mapWithKeys(fn ($z) => [$zones[$z] => ['is_backup' => true]]))
                    ->all()
            );

            return [$name => $courier];
        });
    }

    /**
     * @param  Collection<string, int>  $zones
     * @return Collection<int, Merchant>
     */
    private function merchants(Company $company, Collection $zones): Collection
    {
        return collect(self::MERCHANTS)->map(function (array $m) use ($company, $zones) {
            [$business, $contact, $phone, $zone, $address] = $m;
            $merchant = Merchant::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'phone' => '+225'.$phone],
                ['business_name' => $business, 'contact_name' => $contact, 'whatsapp_phone' => $phone,
                    'pickup_zone_id' => $zones[$zone], 'pickup_address' => $address, 'status' => 'active'],
            );
            // Frais de livraison payés par le destinataire, ajoutés au montant à encaisser
            $merchant->forceFill(['default_fee_payer' => FeePayer::Recipient])->save();
            $this->user($company, Role::MerchantOwner, $contact, $phone, $merchant);

            return $merchant;
        });
    }

    /**
     * Planning : chaque livreur ramasse dans ses communes, avec un remplaçant ; livraisons par secteur ; une règle
     * propre à un client ; une absence (le remplaçant prend le relais).
     *
     * @param  Collection<string, int>  $zones
     * @param  Collection<string, Courier>  $couriers
     * @param  Collection<int, Merchant>  $merchants
     */
    private function planning(Company $company, Collection $zones, Collection $couriers, Collection $merchants): void
    {
        CourierRule::withoutGlobalScopes()->where('company_id', $company->id)->delete();
        CourierAbsence::withoutGlobalScopes()->where('company_id', $company->id)->delete();

        $rule = fn (string $type, string $courier, ?string $backup, array $zoneNames, array $merchantIds = [], array $days = [], ?string $note = null) => CourierRule::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => $type,
            'courier_id' => $couriers[$courier]->id, 'backup_courier_id' => $backup ? $couriers[$backup]->id : null,
            'zone_ids' => collect($zoneNames)->map(fn ($z) => $zones[$z])->values()->all(),
            'merchant_ids' => $merchantIds, 'days' => $days, 'is_active' => true, 'note' => $note,
        ]);

        // Ramassages
        $rule(CourierRule::PICKUP, 'Awa Koné', 'Fatou Bamba', ['Cocody']);
        $rule(CourierRule::PICKUP, 'Yacouba Traoré', 'Ibrahim Diallo', ['Yopougon', 'Attécoubé']);
        $rule(CourierRule::PICKUP, 'Serge Kouassi', 'Koffi Yao', ['Marcory', 'Treichville']);
        $rule(CourierRule::PICKUP, 'Fatou Bamba', 'Awa Koné', ['Plateau', 'Adjamé']);
        $rule(CourierRule::PICKUP, 'Ibrahim Diallo', 'Yacouba Traoré', ['Abobo', 'Anyama']);
        $rule(CourierRule::PICKUP, 'Koffi Yao', 'Serge Kouassi', ['Koumassi', 'Port-Bouët']);
        // Un client à part : Tech Store, ramassé par Koffi le lundi et le jeudi
        $tech = $merchants->firstWhere('business_name', 'Tech Store CI');
        $rule(CourierRule::PICKUP, 'Koffi Yao', 'Serge Kouassi', [], [$tech->id], [1, 4], 'Gros volumes le lundi et le jeudi');

        // Livraisons par secteur
        $rule(CourierRule::DELIVERY, 'Awa Koné', 'Fatou Bamba', ['Cocody', 'Plateau']);
        $rule(CourierRule::DELIVERY, 'Yacouba Traoré', 'Ibrahim Diallo', ['Yopougon', 'Attécoubé', 'Adjamé']);
        $rule(CourierRule::DELIVERY, 'Serge Kouassi', 'Koffi Yao', ['Marcory', 'Treichville', 'Koumassi', 'Port-Bouët']);
        $rule(CourierRule::DELIVERY, 'Ibrahim Diallo', 'Yacouba Traoré', ['Abobo', 'Anyama']);

        // Fatou en congé dans trois jours : Awa reprend ses ramassages
        CourierAbsence::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'courier_id' => $couriers['Fatou Bamba']->id,
            'starts_on' => today()->addDays(3), 'ends_on' => today()->addDays(4), 'reason' => 'Congé',
        ]);
    }

    /**
     * Courses créées comme le ferait chaque e-commerçant : validées et confiées par l'automatisation.
     *
     * @param  Collection<int, Merchant>  $merchants
     * @param  Collection<string, int>  $zones
     */
    private function orders(OrderService $orders, Collection $merchants, Collection $zones, int $count): void
    {
        $destinations = ['Cocody', 'Yopougon', 'Marcory', 'Plateau', 'Abobo', 'Koumassi', 'Treichville', 'Adjamé'];
        $names = ['Kouadio Yves', 'Adjoua Marie', 'Konan Paul', 'Aya Sandrine', 'Bakary Cissé', 'Nadia Kacou', 'Eric Gnagne', 'Rokia Sylla'];

        for ($i = 0; $i < $count; $i++) {
            $merchant = $merchants[$i % $merchants->count()];
            $owner = User::where('merchant_id', $merchant->id)->first();
            $order = $orders->create($owner, $merchant, [
                'recipient_name' => $names[$i % count($names)],
                'recipient_phone' => '07'.str_pad((string) (22000000 + $i), 8, '0', STR_PAD_LEFT),
                'delivery_zone_id' => $zones[$destinations[$i % count($destinations)]],
                'delivery_address' => 'Adresse de démonstration '.($i + 1),
                'items_amount' => 5000 * (1 + $i % 6),
                'description' => 'Colis de démonstration',
            ]);
            $this->line("Course {$order->tracking_code} créée par {$merchant->business_name}");
        }
    }

    private function user(Company $company, Role $role, string $name, string $phone, ?Merchant $merchant = null): User
    {
        $user = User::where('phone', '+225'.$phone)->first() ?? User::create([
            'company_id' => $company->id,
            'merchant_id' => $merchant?->id,
            'name' => $name,
            'phone' => $phone,
            'password' => self::PASSWORD,
        ]);
        $user->syncRoles([$role->value]);
        $user->syncCourierProfile();

        return $user;
    }
}
