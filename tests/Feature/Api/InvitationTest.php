<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Invitation de l'équipe et des livreurs par un lien envoyé par le WhatsApp de l'administrateur : compte créé sans
 * mot de passe, lien personnel à usage unique valable sept jours, session ouverte à l'ouverture.
 */
class InvitationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_a_courier_created_without_password_gets_an_invitation_link(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/users', ['name' => 'Moussa Traoré', 'phone' => '05 11 22 33 44', 'role' => 'courier'])
            ->assertCreated()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.role', 'courier');
        $invitation = $response->json('invitation');
        $this->assertStringContainsString('/invitation/', $invitation['url']);
        $this->assertStringStartsWith('https://wa.me/2250511223344?text=', $invitation['whatsapp_link']);
        $this->assertStringContainsString('Bonjour Moussa', $invitation['text']);
        $this->assertStringContainsString($invitation['url'], $invitation['text']);

        $token = basename($invitation['url']);
        $user = User::where('phone', '+2250511223344')->firstOrFail();
        $this->assertNull($user->password);

        // La personne ouvre le lien : son compte, puis sa session, sans mot de passe
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.name', 'Moussa Traoré')
            ->assertJsonPath('data.role_label', 'Livreur')
            ->assertJsonPath('data.company', $this->company->name);
        $session = $this->postJson("/api/v1/invitations/{$token}/accept", ['device_name' => 'Téléphone de Moussa'])->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'courier');
        $this->assertNotNull($user->fresh()->phone_verified_at);

        // Le lien ne sert qu'une fois
        $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertNotFound();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_invited_person_can_choose_a_password_and_the_link_expires(): void
    {
        $staff = $this->userWithRole(Role::Dispatcher, ['phone' => '0700000077']);
        $staff->forceFill(['password' => null])->save();
        Sanctum::actingAs($this->admin);

        // Nouveau lien pour un compte existant (ancien lien annulé)
        $first = basename($this->postJson("/api/v1/users/{$staff->id}/invitation")->assertCreated()->json('data.url'));
        $second = basename($this->postJson("/api/v1/users/{$staff->id}/invitation")->assertCreated()->json('data.url'));
        $this->getJson("/api/v1/invitations/{$first}")->assertNotFound();

        $this->postJson("/api/v1/invitations/{$second}/accept", ['password' => 'court', 'password_confirmation' => 'court'])->assertUnprocessable();
        $this->postJson("/api/v1/invitations/{$second}/accept", ['password' => 'motdepasse', 'password_confirmation' => 'motdepasse'])
            ->assertOk()->assertJsonPath('user.has_password', true);
        $this->postJson('/api/v1/auth/login', ['login' => '07 00 00 00 77', 'password' => 'motdepasse'])->assertOk();

        // Lien périmé
        $third = basename($this->postJson("/api/v1/users/{$staff->id}/invitation")->assertCreated()->json('data.url'));
        PhoneVerification::where('purpose', PhoneVerification::INVITE)->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/invitations/{$third}")->assertNotFound();
    }

    public function test_invitations_follow_the_user_permissions(): void
    {
        // Un e-commerçant n'est pas invité ainsi, un livreur ne peut inviter personne
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/users/{$this->merchantUser->id}/invitation")->assertUnprocessable();

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/users/{$this->courierB->user->id}/invitation")->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => 'X', 'phone' => '05 00 00 00 01', 'role' => 'courier'])->assertForbidden();
    }
}
