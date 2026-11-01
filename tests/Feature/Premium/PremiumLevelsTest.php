<?php

namespace Tests\Feature\Premium;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\PremiumCard;
use App\Models\PremiumLevel;
use App\Services\Orders\DeliveryDay;
use App\Services\Orders\PickupGroups;
use App\Services\Premium\PremiumCards;
use App\Services\Premium\PremiumLevels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jibiat\Socle\Contract\Contract;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Niveaux Premium : l'entreprise choisit les conditions et les avantages de chaque niveau (dans les limites de la
 * console), le bilan du mois place les marchands, et les avantages s'appliquent tout seuls.
 */
class PremiumLevelsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    /** @var list<HttpRequest> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.jibiat_marchand.premium_secret' => 'secret-de-test', 'services.jibiat_marchand.api_url' => 'https://api.jm.test']);
        $this->merchant->forceFill(['created_at' => now()->subDays(400)])->save();
        Http::fake(function (HttpRequest $request) {
            $this->calls[] = $request;
            $card = Contract::example('premium-card');
            $card['data']['merchant_reference'] = $request['merchant']['reference'] ?? '1';
            $card['data']['code'] = 'K'.count($this->calls).'XXXXXX';

            return Http::response($card, 201);
        });
    }

    /** @return list<array<string, mixed>> */
    private function levels(): array
    {
        $base = ['min_client_days' => 0, 'return_discount_percent' => 0, 'cutoff_extra_minutes' => 0, 'priority_pickup' => false, 'payout_frequency' => null, 'jm_plan' => null, 'jm_free_months' => 0, 'jm_discount_percent' => 0];

        return [
            [...$base, 'name' => 'Argent', 'min_delivered' => 5, 'min_success_rate' => 0.8, 'delivery_discount_percent' => 5],
            [...$base, 'name' => 'Or', 'min_delivered' => 10, 'min_success_rate' => 0.9, 'delivery_discount_percent' => 10, 'return_discount_percent' => 100,
                'cutoff_extra_minutes' => 60, 'priority_pickup' => true, 'payout_frequency' => 'daily', 'jm_plan' => 'starter', 'jm_free_months' => 6, 'jm_discount_percent' => 30],
        ];
    }

    private function history(Merchant $merchant, int $delivered, int $returned = 0): void
    {
        $zones = ['company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'pickup_zone_id' => $this->cocody->id, 'delivery_zone_id' => $this->yopougon->id];
        Order::factory()->count($delivered)->create([...$zones, 'status' => OrderStatus::Delivered, 'created_at' => now()->subDays(10)]);
        if ($returned) {
            Order::factory()->count($returned)->create([...$zones, 'status' => OrderStatus::Returned, 'created_at' => now()->subDays(10)]);
        }
    }

    public function test_levels_are_saved_within_the_console_limits(): void
    {
        Sanctum::actingAs($this->admin);
        $levels = $this->levels();

        $tooMuch = $levels;
        $tooMuch[1]['delivery_discount_percent'] = 40;
        $this->putJson('/api/v1/premium/levels', ['levels' => $tooMuch])->assertStatus(422);

        $business = $levels;
        $business[1]['jm_plan'] = 'business';
        $this->putJson('/api/v1/premium/levels', ['levels' => $business])->assertStatus(422);

        $months = $levels;
        $months[1]['jm_free_months'] = 13;
        $this->putJson('/api/v1/premium/levels', ['levels' => $months])->assertStatus(422);

        $data = $this->putJson('/api/v1/premium/levels', ['levels' => $levels])->assertOk()->json('data');
        $this->assertSame(['Argent', 'Or'], array_column($data['levels'], 'name'));
        $this->assertContains('Jibiat Marchand Starter offert 6 mois', $data['levels'][1]['advantages']);
        $this->assertArrayNotHasKey('business', $data['limits']['plans']);

        // Les e-commerçants ne règlent rien
        Sanctum::actingAs($this->merchantUser);
        $this->putJson('/api/v1/premium/levels', ['levels' => $levels])->assertForbidden();
    }

    public function test_the_monthly_review_places_merchants_and_offers_the_chosen_formula(): void
    {
        app(PremiumCards::class)->updateSettings(['funded_by' => 'company']);
        app(PremiumLevels::class)->save($this->company, $this->levels());
        $silver = Merchant::factory()->create(['company_id' => $this->company->id, 'created_at' => now()->subDays(400)]);
        $this->history($this->merchant, 12);
        $this->history($silver, 6, 1);

        Sanctum::actingAs($this->admin);
        $data = $this->postJson('/api/v1/premium/levels/review')->assertOk()->json('data');
        $this->assertSame(2, $data['up']);
        $this->assertSame(1, $data['cards']);

        $gold = PremiumLevel::where('name', 'Or')->sole();
        $this->assertSame($gold->id, $this->merchant->fresh()->premium_level_id);
        $this->assertSame('Argent', $silver->fresh()->premiumLevel->name);
        $this->assertTrue(OutboundMessage::where('template_name', 'niveau_premium_atteint')->where('merchant_id', $this->merchant->id)->exists());

        // Formule du niveau envoyée à Jibiat Marchand (socle 1.8), carte au prix de la formule
        $offer = collect($this->calls)->first(fn (HttpRequest $r) => $r->method() === 'POST')['offer'];
        $this->assertSame(['plan' => 'starter', 'free_months' => 6, 'discount_rate' => 0.3], $offer);
        $this->assertSame(2000, PremiumCard::where('merchant_id', $this->merchant->id)->sole()->price);

        // Un marchand qui ne remplit plus les conditions redescend
        Order::where('merchant_id', $silver->id)->update(['status' => OrderStatus::Returned->value]);
        app(PremiumLevels::class)->review($this->company);
        $this->assertNull($silver->fresh()->premium_level_id);
    }

    public function test_delivery_and_return_advantages_apply_by_themselves(): void
    {
        app(PremiumLevels::class)->save($this->company, $this->levels());
        $this->history($this->merchant, 12);
        app(PremiumLevels::class)->review($this->company);
        $this->merchant->refresh();

        // −10 % sur le tarif (1 500 F → 1 350 F), gardé sur la course
        $order = $this->createOrder();
        $this->assertSame(1350, $order->delivery_fee);
        $this->assertSame(150, $order->premium_discount);
        $other = $this->createOrder([], $this->admin, Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]));
        $this->assertSame(1500, $other->delivery_fee);

        // Ramassage prioritaire : le client Or passe en tête de la liste des ramassages
        Order::whereKey([$order->id, $other->id])->update(['status' => OrderStatus::Confirmed->value, 'confirmed_at' => now()]);
        $groups = app(PickupGroups::class)->toAssign($this->dispatcher);
        $this->assertSame($this->merchant->id, $groups->first()['merchant']['id']);
        $this->assertSame('Or', $groups->first()['premium']['name']);

        // Paiement chaque jour : en tête de la liste des paiements de la caisse
        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/v1/finance/merchants?all=1')->assertOk()->json('data');
        $this->assertSame('daily', $rows[0]['premium']['payout_frequency']);

        // Heure limite : une heure de plus pour ce marchand
        $this->company->forceFill(['validation_mode' => 'rules', 'daily_cutoff_time' => now()->subMinutes(30)->format('H:i')])->save();
        $day = app(DeliveryDay::class);
        $this->assertSame('cutoff', $day->closedReason($this->company->fresh()));
        $this->assertNull($day->closedReason($this->company->fresh(), $this->merchant->fresh()));
    }

    public function test_the_merchant_sees_his_level_and_what_is_left_for_the_next(): void
    {
        app(PremiumLevels::class)->save($this->company, $this->levels());
        $this->history($this->merchant, 7);
        app(PremiumLevels::class)->review($this->company);

        Sanctum::actingAs($this->merchantUser);
        $levels = $this->getJson('/api/v1/merchant/premium')->assertOk()->json('data.levels');
        $this->assertSame('Argent', $levels['level']['name']);
        $this->assertSame('Or', $levels['next']['name']);
        $this->assertSame(['have' => 7, 'need' => 10], $levels['next']['needs']['delivered']);
    }

    public function test_the_console_sets_the_formulas_companies_may_offer(): void
    {
        $root = $this->userWithRole(Role::SuperAdmin, ['company_id' => null, 'phone' => '+2250700000099']);
        config(['platform.domain' => 'jibiat.test']);
        Sanctum::actingAs($root);
        $settings = $this->getJson('http://admin.jibiat.test/api/v1/console/premium')->assertOk()->json('data');
        $this->assertTrue($settings['plans']['starter']['allowed']);

        $body = [...$settings['settings'], 'plans' => [...$settings['plans'], 'pro' => ['allowed' => false, 'max_months' => 3, 'max_discount' => 20, 'price' => 5000]], 'max_delivery_discount' => 20];
        $this->putJson('http://admin.jibiat.test/api/v1/console/premium', $body)->assertOk()
            ->assertJsonPath('data.plans.pro.allowed', false)->assertJsonPath('data.settings.max_delivery_discount', 20);
    }
}
