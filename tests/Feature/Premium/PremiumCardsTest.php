<?php

namespace Tests\Feature\Premium;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\PremiumCard;
use App\Services\Platform\Billing;
use App\Services\Premium\PremiumCards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Cartes Membre Premium Jibiat Marchand : l'entreprise offre la carte à ses meilleurs marchands (à la main ou
 * toute seule), Jibiat Marchand la crée (appel signé, contrat socle 1.6), le marchand la voit dans son application,
 * et la carte payée par l'entreprise va sur sa facture.
 */
class PremiumCardsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const SECRET = 'secret-de-test-premium';

    /** @var list<HttpRequest> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.jibiat_marchand.premium_secret' => self::SECRET, 'services.jibiat_marchand.api_url' => 'https://api.jm.test']);
        $this->company->forceFill(['slug' => 'livraison-express-ci', 'name' => 'Livraison Express CI'])->save();
        $this->merchant->forceFill(['created_at' => now()->subDays(200)])->save();
        $this->fakeJibiatMarchand();
    }

    private function fakeJibiatMarchand(): void
    {
        $this->calls = [];
        Http::fake(function (HttpRequest $request) {
            $this->calls[] = $request;
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === PremiumCards::PATH) {
                $card = Contract::example('premium-card');
                $card['data']['merchant_reference'] = $request['merchant']['reference'];
                $card['data']['code'] = 'K'.str_pad((string) count($this->calls), 7, 'X');

                return Http::response($card, 201);
            }
            if ($request->method() === 'GET' && $path === PremiumCards::PATH) {
                return Http::response(Contract::example('premium-card-list'));
            }
            if ($request->method() === 'DELETE') {
                $card = Contract::example('premium-card');
                $card['data']['state'] = 'revoked';

                return Http::response($card);
            }

            return Http::response([], 404);
        });
    }

    /** Courses de la période des conditions : livrées et retournées */
    private function history(Merchant $merchant, int $delivered, int $returned = 0): void
    {
        $zones = ['company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'pickup_zone_id' => $this->cocody->id, 'delivery_zone_id' => $this->yopougon->id];
        Order::factory()->count($delivered)->create([...$zones, 'status' => OrderStatus::Delivered, 'created_at' => now()->subDays(10)]);
        if ($returned) {
            Order::factory()->count($returned)->create([...$zones, 'status' => OrderStatus::Returned, 'created_at' => now()->subDays(10)]);
        }
    }

    public function test_the_company_offers_a_card_by_hand_with_a_signed_call_that_follows_the_contract(): void
    {
        $this->history($this->merchant, 12);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/premium/cards', ['merchant_id' => $this->merchant->id])->assertCreated()
            ->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.label', 'Membre Premium Jibiat Marchand n° 007');

        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $this->assertSame('https://api.jm.test/api/partners/v1/premium-cards', $call->url());
        $this->assertSame([], Contract::validate('premium-card-request', json_decode($call->body(), true)));
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $call->header(Contract::PARTNER_TIMESTAMP_HEADER)[0] ?? null, $call->body(), $call->header(Contract::PARTNER_SIGNATURE_HEADER)[0] ?? null));
        $this->assertSame(['livraison-express-ci', (string) $this->merchant->id, 'manual', 12], [
            $call['company']['slug'], $call['merchant']['reference'], $call['reason'], $call['stats']['delivered_90d'],
        ]);

        $card = PremiumCard::sole();
        $this->assertSame([$this->merchant->id, $this->admin->id, 0], [$card->merchant_id, $card->offered_by, $card->price]);
        $this->assertSame($response->json('data.url'), $card->url);
        // Le marchand est prévenu avec le lien de sa carte
        $message = OutboundMessage::query()->where('template_name', 'carte_premium_offerte')->sole();
        $this->assertStringContainsString('Livraison Express CI vous offre la carte Membre Premium Jibiat Marchand : 6 mois offerts', $message->body);
        $this->assertStringContainsString($card->url, $message->body);

        // Une seule carte par marchand
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $this->merchant->id])->assertStatus(422);
        $this->assertSame(1, $this->getJson('/api/v1/premium')->assertOk()->json('data.used'));
    }

    public function test_quota_programme_and_secret_are_checked(): void
    {
        Sanctum::actingAs($this->admin);
        $this->company->forceFill(['premium_quota' => 1])->save();
        $other = Merchant::factory()->create(['company_id' => $this->company->id]);

        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $this->merchant->id])->assertCreated();
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $other->id])->assertStatus(422)
            ->assertJsonPath('message', 'Toutes vos cartes Premium sont offertes (1). Demandez-en d\'autres à Jibiat.');

        // Une carte annulée ne compte plus
        $this->postJson('/api/v1/premium/cards/'.PremiumCard::sole()->id.'/revoke')->assertOk()->assertJsonPath('data.state', 'revoked');
        $delete = collect($this->calls)->first(fn (HttpRequest $r) => $r->method() === 'DELETE');
        $target = parse_url($delete->url(), PHP_URL_PATH).'?'.parse_url($delete->url(), PHP_URL_QUERY);
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $delete->header(Contract::PARTNER_TIMESTAMP_HEADER)[0], $target, $delete->header(Contract::PARTNER_SIGNATURE_HEADER)[0]));
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $other->id])->assertCreated();

        // Programme fermé dans la console, ou serveur sans secret : rien ne part
        $third = Merchant::factory()->create(['company_id' => $this->company->id]);
        $this->company->forceFill(['premium_quota' => 5])->save();
        app(PremiumCards::class)->updateSettings(['enabled' => false]);
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $third->id])->assertStatus(422);
        app(PremiumCards::class)->updateSettings(['enabled' => true]);
        config(['services.jibiat_marchand.premium_secret' => null]);
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $third->id])->assertStatus(422);
        $this->assertSame(1, PremiumCard::query()->whereNull('revoked_at')->count());
    }

    public function test_ranking_and_conditions_of_the_automatic_card(): void
    {
        // Le meilleur : 52 livrées, 2 retours (96 %), client depuis 200 jours
        $this->history($this->merchant, 52, 2);
        // Assez de courses mais trop de retours
        $returns = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Trop de retours', 'created_at' => now()->subDays(200)]);
        $this->history($returns, 50, 15);
        // Client trop récent
        $recent = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Tout nouveau', 'created_at' => now()->subDays(20)]);
        $this->history($recent, 55);
        // Doit de l'argent à l'entreprise depuis 40 jours
        $debtor = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'En retard', 'created_at' => now()->subDays(200)]);
        $this->history($debtor, 60);
        $debt = MerchantLedgerEntry::create(['company_id' => $this->company->id, 'merchant_id' => $debtor->id, 'type' => LedgerEntryType::Adjustment, 'amount' => -20000, 'description' => 'Frais']);
        $debt->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

        $rows = collect(app(PremiumCards::class)->ranking($this->company))->keyBy('merchant_id');
        $this->assertTrue($rows[$this->merchant->id]['eligible']);
        $this->assertSame([52, 0.963, 99], [$rows[$this->merchant->id]['delivered'], $rows[$this->merchant->id]['success_rate'], $rows[$this->merchant->id]['score']]);
        $this->assertFalse($rows[$returns->id]['checks']['success_rate']);
        $this->assertFalse($rows[$recent->id]['checks']['client_since']);
        $this->assertTrue($rows[$debtor->id]['late_debt']);
        $this->assertFalse($rows[$debtor->id]['eligible']);

        // Carte automatique éteinte : rien ; allumée : seul le marchand qui remplit tout reçoit sa carte
        $this->assertSame(0, app(PremiumCards::class)->run()['offered']);
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/premium/auto', ['auto' => true])->assertOk()->assertJsonPath('data.auto', true);
        $this->artisan('premium:award')->assertSuccessful();
        $card = PremiumCard::sole();
        $this->assertSame([$this->merchant->id, 'automatic', null], [$card->merchant_id, $card->reason, $card->offered_by]);
        // Le lendemain : il a déjà sa carte, personne d'autre n'y a droit
        $this->assertSame(0, app(PremiumCards::class)->run()['offered']);
    }

    public function test_the_merchant_sees_his_progress_then_his_card(): void
    {
        $this->history($this->merchant, 38, 2);
        Sanctum::actingAs($this->merchantUser);
        // Carte automatique éteinte : rien à montrer
        $this->getJson('/api/v1/merchant/premium')->assertOk()->assertJsonPath('data', null);

        $this->company->forceFill(['premium_auto' => true])->save();
        $this->getJson('/api/v1/merchant/premium')->assertOk()
            ->assertJsonPath('data.card', null)
            ->assertJsonPath('data.company', 'Livraison Express CI')
            ->assertJsonPath('data.progress.delivered', 38)
            ->assertJsonPath('data.progress.missing', 12)
            ->assertJsonPath('data.progress.target', 50)
            ->assertJsonPath('data.progress.success_rate', 0.95);

        app(PremiumCards::class)->offer($this->company, $this->merchant, 'manual', $this->admin);
        $this->getJson('/api/v1/merchant/premium')->assertOk()
            ->assertJsonPath('data.card.label', 'Membre Premium Jibiat Marchand n° 007')
            ->assertJsonPath('data.card.url', 'https://jibiat-marchand.com/cle/K7M4Q2XP');
        // Pas l'écran de l'entreprise
        $this->getJson('/api/v1/premium')->assertForbidden();
    }

    public function test_rights_on_the_programme(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/premium')->assertOk();
        $this->postJson('/api/v1/premium/cards', ['merchant_id' => $this->merchant->id])->assertForbidden();
        $this->putJson('/api/v1/premium/auto', ['auto' => true])->assertForbidden();
        Sanctum::actingAs($this->userWithRole(Role::Courier));
        $this->getJson('/api/v1/premium')->assertForbidden();
    }

    public function test_cards_are_read_again_from_jibiat_marchand(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id]);
        PremiumCard::create(['company_id' => $this->company->id, 'merchant_id' => $other->id, 'code' => 'R3T9W6HD', 'url' => 'https://jibiat-marchand.com/cle/R3T9W6HD', 'state' => 'available', 'reason' => 'manual', 'offered_at' => now()->subDays(32)]);

        $this->assertSame(1, app(PremiumCards::class)->sync($this->company));
        $card = PremiumCard::sole();
        $this->assertSame(['free', 'Mode Akwaba', 3], [$card->state, $card->store_name, $card->number]);
        $this->assertNotNull($card->free_until);
        $get = collect($this->calls)->sole();
        $this->assertSame('/api/partners/v1/premium-cards?company=livraison-express-ci', parse_url($get->url(), PHP_URL_PATH).'?'.parse_url($get->url(), PHP_URL_QUERY));
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $get->header(Contract::PARTNER_TIMESTAMP_HEADER)[0], '/api/partners/v1/premium-cards?company=livraison-express-ci', $get->header(Contract::PARTNER_SIGNATURE_HEADER)[0]));
    }

    public function test_cards_paid_by_the_company_go_on_its_monthly_invoice(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Abidjan'));
        $plan = Plan::create(['name' => 'Essentiel', 'price_monthly' => 30000, 'included_orders' => null, 'overage_per_order' => 0, 'features' => []]);
        $this->company->forceFill(['plan_id' => $plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();

        // Jibiat paie : la carte ne coûte rien à l'entreprise
        app(PremiumCards::class)->offer($this->company, $this->merchant, 'manual', $this->admin);
        // L'entreprise paie 5 000 F la carte
        app(PremiumCards::class)->updateSettings(['funded_by' => 'company', 'price' => 5000]);
        $other = Merchant::factory()->create(['company_id' => $this->company->id]);
        $paid = app(PremiumCards::class)->offer($this->company, $other, 'manual', $this->admin);
        $this->assertSame(5000, $paid->price);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Africa/Abidjan'));
        $invoice = app(Billing::class)->invoiceFor($this->company->fresh(), '2026-11');
        $this->assertSame([1, 5000, 35000], [$invoice->premium_cards_count, $invoice->premium_cards_amount, $invoice->total]);
        $this->assertSame($invoice->id, $paid->fresh()->platform_invoice_id);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/subscription/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.premium_cards_count', 1)
            ->assertJsonPath('data.premium_cards_amount', 5000);
        // Déjà facturée : pas une deuxième fois
        $this->assertSame([], app(PremiumCards::class)->billable($this->company, CarbonImmutable::now()->addMonth())['ids']);
    }

    public function test_the_console_sets_the_programme(): void
    {
        $owner = $this->userWithRole(Role::SuperAdmin, ['company_id' => null]);
        $support = $this->userWithRole(Role::PlatformSupport, ['company_id' => null]);
        $settings = ['enabled' => true, 'quota' => 15, 'funded_by' => 'company', 'price' => 2000, 'min_delivered' => 40,
            'window_days' => 60, 'min_success_rate' => 0.9, 'min_client_days' => 30, 'max_debt_days' => 15];

        Sanctum::actingAs($support);
        $this->getJson('/api/v1/console/premium')->assertOk()->assertJsonPath('data.settings.quota', 10)->assertJsonPath('data.settings.funded_by', 'jibiat');
        $this->putJson('/api/v1/console/premium', $settings)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/console/premium', $settings)->assertOk()
            ->assertJsonPath('data.settings.quota', 15)
            ->assertJsonPath('data.settings.price', 2000);
        $this->putJson('/api/v1/console/premium/companies/'.$this->company->id, ['quota' => 25])->assertOk()->assertJsonPath('data.effective_quota', 25);
        $row = collect($this->getJson('/api/v1/console/premium')->json('data.companies'))->firstWhere('id', $this->company->id);
        $this->assertSame([25, 25, 0], [$row['quota'], $row['effective_quota'], $row['used']]);
        $this->putJson('/api/v1/console/premium/companies/'.$this->company->id, ['quota' => null])->assertOk()->assertJsonPath('data.effective_quota', 15);
        $this->putJson('/api/v1/console/premium', [...$settings, 'funded_by' => 'marchand'])->assertJsonValidationErrors('funded_by');
    }
}
