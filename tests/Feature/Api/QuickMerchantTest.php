<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Choix de l'e-commerçant à l'enregistrement d'une course : recherche (dernières courses d'abord) et e-commerçant
 * créé avec sa première course, ses accès envoyés dans un seul message de bienvenue.
 */
class QuickMerchantTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function order(array $newMerchant, ?User $actor = null)
    {
        Sanctum::actingAs($actor ?? $this->dispatcher);

        return $this->postJson('/api/v1/orders', [
            'new_merchant' => $newMerchant,
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'fee_payer' => 'merchant',
        ]);
    }

    private function connectQr(): void
    {
        config(['messaging.whatsapp.driver' => 'auto', 'messaging.whatsapp.bridge.url' => 'http://bridge.test', 'messaging.whatsapp.bridge.token' => 'secret-bridge']);
        Http::fake(['bridge.test/*' => Http::response(['id' => 'qr-1'], 202)]);
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'provider' => WhatsAppAccount::QR, 'status' => 'connected']);
    }

    public function test_recent_merchants_come_first_and_search_finds_by_phone(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Awa Mode', 'phone' => '+2250758442109']);
        $this->createOrder();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/merchants?recent=1&per_page=5')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->merchant->id);
        $this->getJson('/api/v1/merchants?search=0758442')->assertOk()->assertJsonPath('data.0.id', $other->id);
    }

    public function test_a_new_merchant_is_created_with_the_order_and_gets_one_welcome_message(): void
    {
        $this->connectQr();

        $response = $this->order(['business_name' => 'Bouba Shop', 'phone' => '07 58 44 21 09', 'pickup_zone_id' => $this->cocody->id])->assertCreated()
            ->assertJsonPath('meta.new_merchant.business_name', 'Bouba Shop')
            ->assertJsonPath('meta.new_merchant.welcome.sent', true);

        $merchant = Merchant::where('business_name', 'Bouba Shop')->firstOrFail();
        $this->assertSame('+2250758442109', $merchant->phone);
        $this->assertSame('Bouba Shop', $merchant->contact_name, 'Sans responsable : le nom de la boutique');
        $owner = User::where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertTrue($owner->hasRole(Role::MerchantOwner->value));
        $this->assertNull($owner->password);
        $this->assertSame($merchant->id, Order::findOrFail($response->json('data.id'))->merchant_id);

        // Un seul message : la bienvenue, avec le code de la course et le lien
        $messages = OutboundMessage::where('merchant_id', $merchant->id)->get();
        $this->assertSame([WhatsAppTemplate::MerchantWelcome->value], $messages->pluck('template_name')->all());
        $this->assertStringContainsString($response->json('data.tracking_code'), $messages->first()->body);
        $this->assertStringContainsString('/invitation/', $messages->first()->body);

        // Le lien ouvre son espace
        $token = str($response->json('meta.new_merchant.welcome.url'))->afterLast('/')->toString();
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.name', 'Bouba Shop');
    }

    public function test_without_whatsapp_the_link_is_given_to_send_by_hand(): void
    {
        $this->order(['business_name' => 'Bouba Shop', 'phone' => '0758442109', 'contact_name' => 'Boubacar', 'pickup_zone_id' => $this->cocody->id])->assertCreated()
            ->assertJsonPath('meta.new_merchant.welcome.sent', false)
            ->assertJsonPath('meta.new_merchant.welcome.phone', fn ($p) => filled($p));

        $merchant = Merchant::where('business_name', 'Bouba Shop')->firstOrFail();
        $this->assertSame('Boubacar', $merchant->contact_name);
        $this->assertSame(0, OutboundMessage::where('merchant_id', $merchant->id)->where('template_name', WhatsAppTemplate::MerchantWelcome->value)->count());
        $this->assertSame(0, OutboundMessage::where('merchant_id', $merchant->id)->where('template_name', WhatsAppTemplate::OrderConfirmed->value)->count(), 'La confirmation part avec la bienvenue');
    }

    public function test_access_not_sent_means_no_message(): void
    {
        $this->connectQr();
        $this->order(['business_name' => 'Bouba Shop', 'phone' => '0758442109', 'send_access' => false, 'pickup_zone_id' => $this->cocody->id])->assertCreated()
            ->assertJsonPath('meta.new_merchant.welcome', null);

        $merchant = Merchant::where('business_name', 'Bouba Shop')->firstOrFail();
        // « Course validée » est éteint par défaut chez un nouveau marchand
        $this->assertSame(0, OutboundMessage::where('merchant_id', $merchant->id)->count());
    }

    public function test_known_phone_roles_and_failed_order_create_nothing(): void
    {
        $this->order(['business_name' => 'Doublon', 'phone' => $this->merchant->phone])
            ->assertUnprocessable()->assertJsonValidationErrors(['new_merchant.phone' => "Ce numéro est déjà celui de {$this->merchant->business_name}."]);

        // Course refusée : pas de compte vide
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/orders', ['new_merchant' => ['business_name' => 'Bouba Shop', 'phone' => '0758442109'], 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id])
            ->assertJsonValidationErrors('fee_payer');

        // Caissier : pas de création d'e-commerçant
        $this->order(['business_name' => 'Bouba Shop', 'phone' => '0758442109'], $this->userWithRole(Role::Cashier))->assertForbidden();
        $this->assertSame(0, Merchant::where('business_name', 'Bouba Shop')->count());

        // Un e-commerçant ne crée pas d'autre e-commerçant
        $this->order(['business_name' => 'Bouba Shop', 'phone' => '0758442109'], $this->merchantUser)->assertJsonValidationErrors('new_merchant');
        $this->assertSame(0, Merchant::where('business_name', 'Bouba Shop')->count());
    }

    public function test_the_price_is_quoted_before_the_merchant_exists(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/quotes', ['pickup_zone_id' => $this->cocody->id, 'delivery_zone_id' => $this->yopougon->id])
            ->assertOk()->assertJsonStructure(['data' => ['total']]);
        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->yopougon->id])->assertJsonValidationErrors('pickup_zone_id');
    }
}
