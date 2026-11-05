<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Jobs\ProcessInboundWhatsApp;
use App\Jobs\SendLiveWhatsApp;
use App\Models\LiveOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\PlatformConversation;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Live\LiveStudio;
use App\Services\WhatsApp\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Commandes du live sur le numéro WhatsApp officiel (agence, sinon Jibiat) : par défaut, sans relier le WhatsApp
 * de l'e-commerçante (option de la console, désactivée).
 */
class LiveOfficialWhatsAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.whatsapp.driver' => 'auto']);
        Queue::fake();
    }

    private function platformNumber(): WhatsAppAccount
    {
        return WhatsAppAccount::create(['owner_type' => WhatsAppAccount::PLATFORM, 'phone_number_id' => '555', 'display_phone' => '+2250100000099',
            'access_token' => 'jeton', 'status' => 'connected', 'verified_name' => 'Jibiat']);
    }

    private function startLive(User $user, string $product = 'Robe wax'): int
    {
        Sanctum::actingAs($user);
        $live = $this->postJson('/api/v1/live/prepare')->assertOk()->json('data.live.id');
        $this->postJson("/api/v1/live/{$live}/products", ['name' => $product, 'price' => 9000, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();

        return $live;
    }

    /** Message reçu par Meta sur un numéro officiel */
    private function meta(string $phoneNumberId, string $id, string $text, string $from = '2250707000001'): void
    {
        app('auth')->forgetGuards();
        $this->postJson('/api/webhooks/whatsapp', ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'contacts' => [['profile' => ['name' => 'Awa']]],
            'messages' => [['id' => $id, 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]])->assertOk();
    }

    /** @return list<string> */
    private function platformReplies(): array
    {
        $texts = [];
        Queue::assertPushed(SendLiveWhatsApp::class, function (SendLiveWhatsApp $job) use (&$texts) {
            $this->assertSame(SendLiveWhatsApp::PLATFORM, $job->session);
            $texts[] = $job->text;

            return true;
        });

        return $texts;
    }

    public function test_orders_arrive_on_jibiat_official_number_by_default(): void
    {
        $this->platformNumber();
        $live = $this->startLive($this->merchantUser);

        // Numéro officiel sur la carte, aucun WhatsApp à relier
        $this->postJson('/api/v1/live/prepare')->assertOk()
            ->assertJsonPath('data.offer.phone', '+2250100000099');
        $this->getJson("/api/v1/live/{$live}")->assertOk()
            ->assertJsonPath('data.whatsapp.mode', 'official')
            ->assertJsonPath('data.whatsapp.auto', true)
            ->assertJsonPath('data.whatsapp.official.name', 'Jibiat')
            ->assertJsonPath('data.whatsapp.merchant_option', false);
        $this->postJson('/api/v1/live/whatsapp/connect', ['phone' => '0102030405'])->assertStatus(422);

        // Message ordinaire : messagerie de la console, pas de réponse du live
        $this->meta('555', 'wamid.1', 'Bonjour, vous livrez à Bouaké ?');
        Queue::assertNotPushed(SendLiveWhatsApp::class);
        $this->assertSame(1, PlatformConversation::count());

        // Code du live : réponse automatique (avec le nom de la boutique), puis la commande
        $this->meta('555', 'wamid.2', 'A1');
        $this->meta('555', 'wamid.2', 'A1'); // renvoi du même webhook par Meta : ignoré
        $this->assertCount(1, $this->platformReplies());
        $this->assertStringContainsString('*Boutique Test*', $this->platformReplies()[0]);
        $this->assertStringContainsString('Pour la livraison', $this->platformReplies()[0]);

        $this->meta('555', 'wamid.3', 'Awa Koné, Yopougon');
        $order = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['Awa Koné', $this->yopougon->id], [$order->recipient_name, $order->delivery_zone_id]);
        $this->assertSame('whatsapp', LiveOrder::first()->source);
        $this->assertStringContainsString("C'est noté, Awa", $this->platformReplies()[1]);
        // La console n'a reçu que le message ordinaire
        $this->assertSame('Bonjour, vous livrez à Bouaké ?', PlatformConversation::first()->last_message);
    }

    public function test_agency_official_number_is_used_first_and_same_code_asks_which_shop(): void
    {
        $this->platformNumber();
        $agency = WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '777',
            'display_phone' => '+2250100000077', 'access_token' => 'jeton-agence', 'status' => 'connected']);
        $this->startLive($this->merchantUser);

        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Chez Mariam',
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Angré']);
        $otherUser = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => $other->id]);
        $otherLive = $this->startLive($otherUser, 'Sac cuir');

        $this->getJson("/api/v1/live/{$otherLive}")->assertOk()
            ->assertJsonPath('data.whatsapp.official.phone', '+2250100000077')
            ->assertJsonPath('data.whatsapp.official.name', $this->company->name);

        $say = function (string $id, string $text): void {
            app('auth')->forgetGuards();
            $this->meta('777', $id, $text);
            $job = collect(Queue::pushed(ProcessInboundWhatsApp::class))->last();
            $job->handle(app(Conversation::class));
        };

        // A1 existe dans les deux lives : la cliente choisit la boutique
        $say('wamid.10', 'A1');
        $this->assertStringContainsString("1. Boutique Test\n2. Chez Mariam", OutboundMessage::withoutGlobalScopes()->latest('id')->value('body'));
        $this->assertSame($agency->id, OutboundMessage::withoutGlobalScopes()->latest('id')->value('whatsapp_account_id'));

        $say('wamid.11', '2');
        $this->assertStringContainsString('*Chez Mariam*', OutboundMessage::withoutGlobalScopes()->latest('id')->value('body'));
        $this->assertStringContainsString('Sac cuir', OutboundMessage::withoutGlobalScopes()->latest('id')->value('body'));

        $say('wamid.12', 'Awa, Yopougon');
        $this->assertSame($other->id, Order::withoutGlobalScopes()->latest('id')->value('merchant_id'));
        Queue::assertNotPushed(SendLiveWhatsApp::class);
    }

    public function test_console_option_shows_the_merchant_whatsapp_next_to_the_official_number(): void
    {
        $this->platformNumber();
        config(['messaging.whatsapp.bridge.token' => 'secret-bridge', 'messaging.whatsapp.bridge.url' => 'http://bridge.test']);
        $live = $this->startLive($this->merchantUser);

        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $this->putJson('/api/v1/console/live', ['merchant_whatsapp' => true])->assertOk()->assertJsonPath('data.settings.merchant_whatsapp', true);
        $this->assertTrue(app(LiveStudio::class)->settings()['merchant_whatsapp']);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/live/{$live}")->assertOk()
            ->assertJsonPath('data.whatsapp.mode', 'official')
            ->assertJsonPath('data.whatsapp.merchant_option', true)
            ->assertJsonPath('data.whatsapp.available', true);

        // WhatsApp de l'e-commerçante relié : il passe avant le numéro officiel, jusqu'à ce que l'option soit retirée
        $this->merchant->forceFill(['live_wa_status' => 'connected', 'live_wa_phone' => '+2250102030405'])->save();
        $this->getJson("/api/v1/live/{$live}")->assertJsonPath('data.whatsapp.mode', 'merchant');
        $this->postJson('/api/v1/live/prepare')->assertJsonPath('data.offer.phone', '+2250102030405');

        app(LiveStudio::class)->updateSettings(['merchant_whatsapp' => false]);
        $this->getJson("/api/v1/live/{$live}")->assertJsonPath('data.whatsapp.mode', 'official');
        $this->postJson('/api/v1/live/prepare')->assertJsonPath('data.offer.phone', '+2250100000099');
    }
}
