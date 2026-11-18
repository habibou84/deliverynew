<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Fiche e-commerçant › Compte : l'agence change le numéro et le mot de passe de connexion de la boutique.
 */
class MerchantAccountsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_admin_lists_and_changes_the_phone_and_password_of_a_merchant_account(): void
    {
        $this->merchant->update(['phone' => $this->merchantUser->phone]);
        $this->merchantUser->createToken('appareil');
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/users?merchant_id={$this->merchant->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->merchantUser->id);

        $this->putJson("/api/v1/users/{$this->merchantUser->id}", ['phone' => '07 99 88 77 66', 'password' => 'nouveaumotdepasse'])->assertOk()
            ->assertJsonPath('data.phone', '+2250799887766');

        $user = $this->merchantUser->fresh();
        $this->assertTrue(Hash::check('nouveaumotdepasse', $user->password));
        // Déconnecté partout ; le numéro de la boutique suit celui du gérant
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('+2250799887766', $this->merchant->fresh()->phone);
    }

    public function test_a_dispatcher_cannot_change_merchant_credentials(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $this->putJson("/api/v1/users/{$this->merchantUser->id}", ['password' => 'nouveaumotdepasse'])->assertForbidden();
    }
}
