<?php

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Notifications\AccountDeletionRequested;
use App\Services\Accounts\AccountDeletions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Suppression du compte depuis le profil (e-commerçant, livreur), exigée par Google Play : compte coupé tout de
 * suite, entreprise prévenue, données personnelles effacées après 30 jours sauf annulation.
 */
class AccountDeletionTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_merchant_deletes_the_account_and_is_logged_out_everywhere(): void
    {
        Notification::fake();
        $this->merchantUser->forceFill(['phone' => '+2250700000123', 'email' => 'awa@exemple.ci'])->save();
        $token = $this->merchantUser->createToken('téléphone')->plainTextToken;
        $this->merchantUser->createToken('autre appareil');
        $order = $this->createOrder();

        $this->postJson('/api/v1/account/deletion', [], ['Authorization' => "Bearer {$token}"])->assertJsonValidationErrors('confirm');
        $this->postJson('/api/v1/account/deletion', ['confirm' => true], ['Authorization' => "Bearer {$token}"])->assertCreated()
            ->assertJsonPath('data.erase_after', now()->addDays(30)->toDateString());

        $user = $this->merchantUser->fresh();
        $this->assertSame(UserStatus::Suspended, $user->status);
        $this->assertSame(0, $user->tokens()->count());
        Notification::assertSentTo($this->admin, AccountDeletionRequested::class);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

        // L'entreprise voit la demande
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/account-deletions')->assertOk()->assertJsonPath('data.0.name', $user->name);

        // 30 jours plus tard : données personnelles effacées, la course reste
        $this->travel(29)->days();
        $this->artisan('accounts:erase-deleted')->assertSuccessful();
        $this->assertNotNull(User::find($user->id));
        $this->travel(2)->days();
        $this->artisan('accounts:erase-deleted')->assertSuccessful();

        $erased = User::withTrashed()->find($user->id);
        $this->assertTrue($erased->trashed());
        $this->assertSame([AccountDeletions::ERASED_NAME, null, null], [$erased->name, $erased->phone, $erased->email]);
        $this->assertNotNull(AccountDeletionRequest::withoutGlobalScopes()->sole()->erased_at);
        $this->assertNotNull($order->fresh());
        $this->assertSame('Boutique Test', $order->fresh()->merchant->business_name);
        $this->getJson('/api/v1/account-deletions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_company_cancels_before_erasure(): void
    {
        $courier = $this->courierA->user;
        Sanctum::actingAs($courier);
        $this->postJson('/api/v1/account/deletion', ['confirm' => true])->assertCreated();
        $deletion = AccountDeletionRequest::withoutGlobalScopes()->sole();

        // Réservé à ceux qui gèrent les utilisateurs
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/account-deletions/{$deletion->id}/cancel")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/account-deletions/{$deletion->id}/cancel")->assertOk();
        $this->assertSame(UserStatus::Active, $courier->fresh()->status);
        $this->postJson("/api/v1/account-deletions/{$deletion->id}/cancel")->assertStatus(422);

        $this->travel(31)->days();
        $this->artisan('accounts:erase-deleted')->assertSuccessful();
        $this->assertNotSame(AccountDeletions::ERASED_NAME, $courier->fresh()->name);
        $this->assertFalse($courier->fresh()->trashed());
    }

    public function test_reserved_to_merchants_and_couriers(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/account/deletion', ['confirm' => true])->assertForbidden();
        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
    }

    public function test_public_page_explains_how_to_delete_the_account(): void
    {
        $this->get('/suppression-du-compte')->assertOk()
            ->assertSee('Supprimer votre compte')
            ->assertSee('Supprimer mon compte')
            ->assertSee('30 jours');
        $this->get('/confidentialite')->assertOk()->assertSee('href="/suppression-du-compte"', false);
    }
}
