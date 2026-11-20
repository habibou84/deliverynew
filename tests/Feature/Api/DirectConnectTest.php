<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\ConnectRequest;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\ConnectRequestReceived;
use App\Notifications\MerchantSignedUp;
use App\Services\Connect\DirectConnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Connexion directe de Jibiat Marchand et devis de l'annuaire (contrat socle 1.12) : de serveur à serveur,
 * requêtes signées, sans redirection ni mot de passe. Un compte existant n'est jamais rattaché sans le marchand.
 */
class DirectConnectTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const SECRET = 'secret-partage-de-test';

    private const DECISIONS = 'https://api.jibiat-marchand.test/api/partners/v1/connections';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'platform.domain' => 'jibiat.test',
            'connect.clients.jibiat-marchand.secret' => self::SECRET,
            'services.jibiat_marchand.api_url' => 'https://api.jibiat-marchand.test',
        ]);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express', 'merchant_signup' => true, 'directory_listed' => true]);
        $this->cocody->update(['place_code' => 'abidjan-cocody']);
        $this->yopougon->update(['place_code' => 'abidjan-yopougon']);
        Http::fake(['api.jibiat-marchand.test/*' => Http::response(['ok' => true])]);
    }

    private function at(string $sub, string $path): string
    {
        return 'http://'.($sub === '' ? '' : "{$sub}.").'jibiat.test'.$path;
    }

    /**
     * Appel signé de Jibiat Marchand, comme son serveur le fait.
     */
    private function signed(string $method, string $path, array $body = [], ?string $secret = null): TestResponse
    {
        $timestamp = time();
        $raw = $method === 'POST' ? json_encode($body) : '';
        $signature = WebhookSignature::sign($secret ?? self::SECRET, $timestamp, $method === 'POST' ? $raw : $path);
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', Contract::PARTNER_TIMESTAMP_HEADER)) => (string) $timestamp,
            'HTTP_'.strtoupper(str_replace('-', '_', Contract::PARTNER_SIGNATURE_HEADER)) => $signature,
        ];

        return $this->call($method, $this->at('rapide', $path), [], [], [], $headers, $raw);
    }

    private function demand(array $overrides = []): array
    {
        return array_replace_recursive(Contract::example('connect-direct-request'), [
            'request_id' => 'demande-'.uniqid(),
            'owner' => ['phone' => '+2250707112233'],
            'pickup' => ['place' => 'abidjan-cocody-angre'],
            'waiting_orders' => 0,
        ], $overrides);
    }

    /**
     * Dernière décision reçue par Jibiat Marchand, signature vérifiée.
     *
     * @return array<string, mixed>
     */
    private function lastDecision(): array
    {
        $sent = Http::recorded(fn (Request $r) => $r->url() === self::DECISIONS)->last();
        $this->assertNotNull($sent, 'Aucune décision envoyée à Jibiat Marchand');
        [$request] = $sent;
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $request->header(Contract::PARTNER_TIMESTAMP_HEADER)[0] ?? null, $request->body(), $request->header(Contract::PARTNER_SIGNATURE_HEADER)[0] ?? null));
        $this->assertSame([], Contract::validate('connect-decision', $request->data()));

        return $request->data();
    }

    public function test_directory_quotes_give_the_default_price_of_each_company_for_a_place(): void
    {
        $response = $this->postJson($this->at('', '/api/public/directory/quotes'), [
            'delivery_place' => 'abidjan-yopougon', 'pickup_place' => 'abidjan-cocody-angre',
        ])->assertOk();

        $this->assertSame([], Contract::validate('directory-quotes', $response->json()));
        $this->assertSame(['slug' => 'rapide', 'covered' => true, 'total' => 1500], array_intersect_key($response->json('data.0'), array_flip(['slug', 'covered', 'total'])));
        $this->assertNotNull($response->json('data.0.earliest_date'));

        // Lieu pas desservi : dit, sans prix
        $this->postJson($this->at('', '/api/public/directory/quotes'), ['delivery_place' => 'abidjan-marcory'])
            ->assertJsonPath('data.0.covered', false)->assertJsonPath('data.0.total', null);

        // Une entreprise qui ne figure pas dans l'annuaire n'est pas proposée
        $this->company->update(['directory_listed' => false]);
        $this->postJson($this->at('', '/api/public/directory/quotes'), ['delivery_place' => 'abidjan-yopougon'])->assertJsonCount(0, 'data');
    }

    public function test_an_unsigned_or_badly_signed_call_is_refused(): void
    {
        $this->postJson($this->at('rapide', '/api/public/connect/direct'), $this->demand())->assertStatus(401);
        $this->signed('POST', '/api/public/connect/direct', $this->demand(), 'mauvais-secret')->assertStatus(401);

        // Téléphone non vérifié par Jibiat Marchand : refusé
        $demand = $this->demand();
        unset($demand['owner']['phone_verified_at']);
        $this->signed('POST', '/api/public/connect/direct', $demand)->assertStatus(422);

        $this->assertSame(0, ConnectRequest::withoutGlobalScopes()->count());
    }

    public function test_open_signup_connects_at_once_and_gives_the_key_server_to_server(): void
    {
        Notification::fake();
        $demand = $this->demand();

        $response = $this->signed('POST', '/api/public/connect/direct', $demand)->assertCreated();
        $this->assertSame([], Contract::validate('connect-direct', $response->json()));
        $response->assertJsonPath('data.status', 'connected');

        $key = ApiKey::findValid($response->json('data.connection.api_key'));
        $this->assertNotNull($key);
        $this->assertSame('jibiat-marchand', $key->client);
        $merchant = Merchant::withoutGlobalScopes()->find($key->merchant_id);
        $this->assertSame('Awa Mode', $merchant->business_name);
        $this->assertSame($this->cocody->id, $merchant->pickup_zone_id);
        $this->assertTrue(User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->first()->hasRole('merchant_owner'));
        Notification::assertSentTo($this->admin, MerchantSignedUp::class, fn ($n) => $n->fromApp === 'Jibiat Marchand');

        // Même demande, même réponse (réponse perdue par Jibiat Marchand) : pas de second compte
        $this->signed('POST', '/api/public/connect/direct', $demand)->assertOk()
            ->assertJsonPath('data.connection.api_key', $response->json('data.connection.api_key'));
        $this->assertSame(1, Merchant::withoutGlobalScopes()->where('phone', '+2250707112233')->count());

        // Clé gardée 24 h au plus
        $this->travel(25)->hours();
        app(DirectConnect::class)->expireDue();
        $this->signed('GET', "/api/public/connect/direct/{$demand['request_id']}")->assertJsonPath('data.status', 'connected')->assertJsonPath('data.connection', null);
    }

    public function test_closed_signup_waits_for_the_agency_then_sends_the_key_to_jibiat_marchand(): void
    {
        Notification::fake();
        $this->company->update(['merchant_signup' => false]);
        $demand = $this->demand(['waiting_orders' => 1]);

        $response = $this->signed('POST', '/api/public/connect/direct', $demand)->assertCreated();
        $this->assertSame([], Contract::validate('connect-direct', $response->json()));
        $response->assertJsonPath('data.status', 'pending')->assertJsonPath('data.connection', null);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, strtotime($response->json('data.decide_before')), 5);
        Notification::assertSentTo($this->admin, ConnectRequestReceived::class, fn ($n) => str_contains($n->toArray($this->admin)['body'], '1 colis attend déjà'));

        Sanctum::actingAs($this->admin);
        $list = $this->getJson($this->at('rapide', '/api/v1/connect-requests'))->assertOk();
        $this->assertSame(1, $list->json('data.0.waiting_orders'));
        $this->assertTrue($list->json('data.0.direct'));
        $id = $list->json('data.0.id');

        $merchantId = $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$id}/accept"))->assertOk()->json('data.merchant_id');

        $decision = $this->lastDecision();
        $this->assertSame('connected', $decision['status']);
        $this->assertSame($demand['request_id'], $decision['request_id']);
        $this->assertSame($merchantId, ApiKey::findValid($decision['connection']['api_key'])->merchant_id);

        // Remise : la clé n'est plus gardée
        $stored = ConnectRequest::withoutGlobalScopes()->find($id);
        $this->assertNotNull($stored->decision_sent_at);
        $this->assertNull($stored->pending_key);
        $this->signed('GET', "/api/public/connect/direct/{$demand['request_id']}")->assertJsonPath('data.status', 'connected')->assertJsonPath('data.connection', null);
    }

    public function test_a_declined_expired_or_cancelled_request_is_told_to_jibiat_marchand(): void
    {
        $this->company->update(['merchant_signup' => false]);

        // Refusée avec un motif
        $declined = $this->demand();
        $this->signed('POST', '/api/public/connect/direct', $declined)->assertCreated();
        Sanctum::actingAs($this->admin);
        $id = ConnectRequest::withoutGlobalScopes()->where('request_id', $declined['request_id'])->value('id');
        $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$id}/decline"), ['reason' => 'Nous ne livrons pas encore votre commune.'])->assertNoContent();
        $this->assertSame(['declined', 'Nous ne livrons pas encore votre commune.', null], [$this->lastDecision()['status'], $this->lastDecision()['reason'], $this->lastDecision()['connection']]);

        // Un colis attend et l'agence ne répond pas en 24 h : expirée
        $late = $this->demand(['owner' => ['phone' => '+2250707445566'], 'waiting_orders' => 2]);
        $this->signed('POST', '/api/public/connect/direct', $late)->assertCreated();
        $this->travel(23)->hours();
        $this->assertSame(0, app(DirectConnect::class)->expireDue());
        $this->travel(2)->hours();
        $this->assertSame(1, app(DirectConnect::class)->expireDue());
        $this->assertSame(['expired', $late['request_id']], [$this->lastDecision()['status'], $this->lastDecision()['request_id']]);
        $lateId = ConnectRequest::withoutGlobalScopes()->where('request_id', $late['request_id'])->value('id');
        $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$lateId}/accept"))->assertStatus(422);

        // Annulée par Jibiat Marchand : ne peut plus être acceptée
        $cancelled = $this->demand(['owner' => ['phone' => '+2250707778899']]);
        $this->signed('POST', '/api/public/connect/direct', $cancelled)->assertCreated();
        $this->signed('DELETE', "/api/public/connect/direct/{$cancelled['request_id']}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $cancelledId = ConnectRequest::withoutGlobalScopes()->where('request_id', $cancelled['request_id'])->value('id');
        $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$cancelledId}/accept"))->assertStatus(422);
        $this->assertSame(0, Merchant::withoutGlobalScopes()->whereIn('phone', ['+2250707445566', '+2250707778899'])->count());
    }

    public function test_an_existing_merchant_is_never_attached_without_saying_yes(): void
    {
        $demand = $this->demand(['owner' => ['phone' => $this->merchant->phone]]);

        $response = $this->signed('POST', '/api/public/connect/direct', $demand)->assertCreated();
        $this->assertSame([], Contract::validate('connect-direct', $response->json()));
        $response->assertJsonPath('data.status', 'confirmation')->assertJsonPath('data.connection', null);
        $this->assertSame(0, ApiKey::withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());

        // L'équipe ne la voit pas : c'est au marchand de répondre
        Sanctum::actingAs($this->admin);
        $this->getJson($this->at('rapide', '/api/v1/connect-requests'))->assertJsonPath('pending', 0);
        $this->app['auth']->forgetGuards();

        // Lien signé reçu sur WhatsApp : la page, puis « Oui »
        $request = ConnectRequest::withoutGlobalScopes()->where('request_id', $demand['request_id'])->first();
        $url = str_replace('https://', 'http://', DirectConnect::confirmUrl($request));
        $this->get($url)->assertOk()->assertSee('Oui, c\'est ma boutique', false);
        $this->get(preg_replace('/signature=[^&]+/', 'signature=faux', $url))->assertForbidden();
        $this->post($url, ['answer' => 'yes'])->assertOk()->assertSee('C\'est fait', false);

        $decision = $this->lastDecision();
        $this->assertSame('connected', $decision['status']);
        $this->assertSame($this->merchant->id, ApiKey::findValid($decision['connection']['api_key'])->merchant_id);
        $this->assertSame(1, Merchant::withoutGlobalScopes()->where('phone', $this->merchant->phone)->count());

        // Une autre demande refusée par le marchand : rien n'est rattaché
        $other = $this->demand(['owner' => ['phone' => $this->merchant->phone]]);
        $this->signed('POST', '/api/public/connect/direct', $other)->assertCreated();
        $second = ConnectRequest::withoutGlobalScopes()->where('request_id', $other['request_id'])->first();
        $this->post(str_replace('https://', 'http://', DirectConnect::confirmUrl($second)), ['answer' => 'no'])->assertOk()->assertSee('Connexion refusée');
        $this->assertSame('declined', $this->lastDecision()['status']);
        $this->assertSame(1, ApiKey::withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
    }

    public function test_the_directory_announces_the_direct_connection(): void
    {
        $this->getJson($this->at('', '/api/public/directory'))->assertOk()->assertJsonPath('data.0.direct', true);
    }
}
