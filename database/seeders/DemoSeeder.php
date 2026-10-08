<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
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

        $this->user($company, Role::Admin, 'Administrateur', '0700000001', 'admin@livraison.test');
        $this->user($company, Role::Dispatcher, 'Dispatcher', '0700000002', 'dispatch@livraison.test');
        $this->user($company, Role::Cashier, 'Caissier', '0700000003', 'caisse@livraison.test');
        $this->user($company, Role::Courier, 'Koffi Livreur', '0700000004', null);
        $this->user($company, Role::Courier, 'Awa Livreuse', '0700000005', null);
    }

    private function user(?Company $company, Role $role, string $name, string $phone, ?string $email): void
    {
        $user = User::where('phone', '+225'.$phone)->first() ?? User::create([
            'company_id' => $company?->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'password' => 'password',
        ]);

        $user->syncRoles([$role->value]);
    }
}
