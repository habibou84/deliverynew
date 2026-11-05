<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\UserAccountEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Utilisateurs : onglets et compteurs, droits par ligne, déconnexion de tous les appareils, historique du compte.
 */
class UsersPageTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_tabs_and_counters(): void
    {
        $this->admin->forceFill(['last_login_at' => now()])->save();
        $this->dispatcher->forceFill(['last_login_at' => now()->subDays(40)])->save();
        $this->courierA->user->forceFill(['last_login_at' => now()])->save();
        $this->courierB->user->forceFill(['last_login_at' => now(), 'status' => 'suspended'])->save();
        Sanctum::actingAs($this->admin);

        $summary = $this->getJson('/api/v1/users/summary')->assertOk()->json('data');
        $this->assertSame([2, 2, 1, 1], [$summary['team'], $summary['couriers'], $summary['merchants'], $summary['suspended']]);
        // Le dispatcher inactif depuis 40 jours ; l'e-commerçant jamais connecté ne compte pas
        $this->assertSame(1, $summary['inactive']);
        $this->assertSame(['active' => 1, 'suspended' => 1], $summary['roles']['courier']);

        $names = fn (string $scope) => collect($this->getJson("/api/v1/users?scope={$scope}")->assertOk()->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing([$this->admin->name, $this->dispatcher->name], $names('team'));
        $this->assertSame([$this->merchantUser->name], $names('merchants'));
        $this->assertSame([$this->dispatcher->name], $names('inactive'));
        $this->assertSame(['Awa Livreuse'], $names('suspended'));
        $this->getJson('/api/v1/users?scope=couriers&search=koffi')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.courier.id', $this->courierA->id)->assertJsonPath('meta.total', 1);
    }

    public function test_row_abilities_protect_other_admins_and_a_name_alone_can_be_fixed(): void
    {
        $other = $this->userWithRole(Role::Admin, ['name' => 'Mariam Diallo']);
        Sanctum::actingAs($this->admin);

        $rows = collect($this->getJson('/api/v1/users?scope=team')->json('data'))->keyBy('id');
        $this->assertSame(['update' => true, 'credentials' => false, 'access' => false], $rows[$other->id]['abilities']);
        $this->assertSame(['update' => true, 'credentials' => true, 'access' => true], $rows[$this->dispatcher->id]['abilities']);

        // Seul le nom est envoyé : accepté ; le téléphone d'un autre administrateur : refusé
        $this->patchJson("/api/v1/users/{$other->id}", ['name' => 'Mariam D.'])->assertOk();
        $this->patchJson("/api/v1/users/{$other->id}", ['name' => 'Mariam D.', 'phone' => '+2250700000077'])->assertForbidden();
        $this->postJson("/api/v1/users/{$other->id}/logout")->assertForbidden();
    }

    public function test_logout_everywhere_and_history(): void
    {
        $token = $this->dispatcher->createToken('téléphone')->plainTextToken;
        Sanctum::actingAs($this->admin);

        $user = $this->postJson('/api/v1/users', ['name' => 'Yao Serge', 'phone' => '0798765432', 'role' => 'cashier'])->assertCreated()->json('data');
        $this->patchJson("/api/v1/users/{$user['id']}", ['role' => 'dispatcher'])->assertOk();
        $this->patchJson("/api/v1/users/{$user['id']}", ['status' => 'suspended'])->assertOk();
        $this->patchJson("/api/v1/users/{$user['id']}", ['status' => 'active', 'name' => 'Yao Serge K.'])->assertOk();

        $texts = collect($this->getJson("/api/v1/users/{$user['id']}/history")->assertOk()->json('data'))->pluck('text')->all();
        $this->assertSame([
            'Nom changé : Yao Serge → Yao Serge K.',
            'Compte réactivé',
            'Compte suspendu',
            'Rôle changé : Caissier → Dispatcher',
            'Lien de connexion préparé',
            'Compte créé (Caissier)',
        ], $texts);
        $this->assertSame($this->admin->name, $this->getJson("/api/v1/users/{$user['id']}/history")->json('data.0.by'));

        $this->postJson("/api/v1/users/{$this->dispatcher->id}/logout")->assertOk();
        $this->assertSame(0, $this->dispatcher->tokens()->count());
        $this->assertTrue(UserAccountEvent::where('user_id', $this->dispatcher->id)->where('action', UserAccountEvent::LOGGED_OUT)->exists());
        $this->assertNotNull($token);
        $this->postJson("/api/v1/users/{$this->admin->id}/logout")->assertUnprocessable();

        // Le dispatch voit l'historique, mais ne déconnecte personne
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/users/{$user['id']}/history")->assertOk();
        $this->postJson("/api/v1/users/{$user['id']}/logout")->assertForbidden();
    }
}
