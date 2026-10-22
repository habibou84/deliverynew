<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Bascule depuis l'ancienne application : à la date choisie, un e-commerçant ou un livreur qui ouvre l'ancienne
 * application arrive sur Jibiat déjà connecté (ticket à usage unique, session sur son espace).
 */
class LegacySwitchTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    // Mot de passe « secret123 » haché par l'ancienne application (bcrypt)
    private const HASH = '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function key(): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/company/legacy-switch/key')->assertCreated()->json('data.key');
    }

    private function ask(string $key, array $payload)
    {
        return $this->withToken($key)->postJson('/api/legacy-switch', $payload);
    }

    /** Ticket du lien renvoyé par la bascule */
    private function ticket(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    public function test_nothing_happens_before_the_date_then_the_account_arrives_logged_in(): void
    {
        $key = $this->key();
        $merchant = ['phone' => $this->merchantUser->phone, 'usertype' => 'client'];

        // Pas de date : l'ancienne application continue
        $this->ask($key, $merchant)->assertOk()->assertJsonPath('status', 'scheduled')->assertJsonPath('switch_at', null);

        // Date à venir : renvoyée, toujours rien
        $this->putJson('/api/v1/company/legacy-switch', ['switch_at' => now()->addDay()->toIso8601String()])->assertOk()
            ->assertJsonPath('data.started', false);
        $this->ask($key, $merchant)->assertOk()->assertJsonPath('status', 'scheduled');

        // Le jour venu : lien de connexion à usage unique
        $this->travel(2)->days();
        $url = $this->ask($key, $merchant)->assertOk()->assertJsonPath('status', 'switch')->json('url');
        $this->assertStringContainsString('/bascule/', $url);

        $location = $this->get('/bascule/'.$this->ticket($url))->assertRedirect()->headers->get('Location');
        $this->assertMatchesRegularExpression('#/marchand\#session=.+$#', $location);
        $token = urldecode(substr($location, strpos($location, '#session=') + 9));
        $this->assertSame($this->merchantUser->id, PersonalAccessToken::findToken($token)->tokenable_id);
        $this->assertNotNull($this->merchantUser->fresh()->legacy_switched_at);

        // Un ticket ne sert qu'une fois
        $this->get('/bascule/'.$this->ticket($url))->assertRedirect('/');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-switch')->assertOk()
            ->assertJsonPath('data.started', true)
            ->assertJsonPath('data.switched.merchants', 1)
            ->assertJsonPath('data.switched.couriers', 0);
    }

    public function test_couriers_land_on_their_space_and_new_accounts_are_taken_over(): void
    {
        $key = $this->key();
        $this->putJson('/api/v1/company/legacy-switch', ['switch_at' => now()->subMinute()->toIso8601String()])->assertOk();

        $url = $this->ask($key, ['phone' => $this->courierA->user->phone, 'usertype' => 'livreur'])->assertOk()->json('url');
        $this->assertStringContainsString('/livreur#session=', $this->get('/bascule/'.$this->ticket($url))->headers->get('Location'));

        // Livreur créé dans l'ancienne application après la reprise : repris avec sa fiche, puis connecté
        $this->ask($key, ['phone' => '0153141666', 'usertype' => 'livreur'])->assertNotFound()->assertJsonPath('status', 'unknown');
        $url = $this->ask($key, [
            'phone' => '0153141666', 'usertype' => 'livreur',
            'users' => [['id' => 20, 'name' => 'Adamo', 'email' => null, 'phone' => '0153141666', 'usertype' => 'livreur', 'password' => self::HASH,
                'created_at' => '2026-01-01 10:00:00', 'livreur_id' => 20, 'client_id' => null]],
            'livreurs' => [['id' => 20, 'nom' => 'Adamo', 'phone' => '0153141666', 'adresse' => 'abatta', 'city' => 'Cocody', 'status' => 'active', 'user_id' => 20]],
        ])->assertOk()->assertJsonPath('status', 'switch')->json('url');
        $this->get('/bascule/'.$this->ticket($url))->assertRedirect();
        $this->assertTrue(User::where('phone', '+2250153141666')->sole()->isCourier());

        // Un compte suspendu reste sur l'ancienne application
        $this->courierB->user->update(['status' => 'suspended']);
        $this->ask($key, ['phone' => $this->courierB->user->phone, 'usertype' => 'livreur'])->assertForbidden();
    }

    public function test_the_key_is_required_and_the_switch_can_be_cancelled(): void
    {
        $this->ask('jbs_inconnue', ['phone' => $this->merchantUser->phone, 'usertype' => 'client'])->assertUnauthorized();

        $key = $this->key();
        $this->putJson('/api/v1/company/legacy-switch', ['switch_at' => now()->subMinute()->toIso8601String()])->assertOk();
        $this->putJson('/api/v1/company/legacy-switch', ['switch_at' => null])->assertOk()->assertJsonPath('data.switch_at', null);
        $this->ask($key, ['phone' => $this->merchantUser->phone, 'usertype' => 'client'])->assertJsonPath('status', 'scheduled');

        // Clé désactivée : plus personne n'est envoyé
        $this->deleteJson('/api/v1/company/legacy-switch/key')->assertOk()->assertJsonPath('data.key_active', false);
        $this->ask($key, ['phone' => $this->merchantUser->phone, 'usertype' => 'client'])->assertUnauthorized();

        // Réservé aux administrateurs
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-switch')->assertForbidden();
    }
}
