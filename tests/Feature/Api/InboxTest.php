<?php

namespace Tests\Feature\Api;

use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Messages › Reçus : conversations WhatsApp, contact reconnu, non lus, réponse dans les 24 h.
 */
class InboxTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const CLIENT = '+2250711112222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Queue::fake();
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]));
    }

    private function whatsapp(string $provider = 'meta'): void
    {
        WhatsAppAccount::create([
            'company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '1098765',
            'access_token' => 'tok', 'display_phone' => '0700000099', ...($provider === 'qr' ? ['provider' => 'qr'] : []),
        ]);
    }

    private function receive(string $from, string $text, array $extra = []): InboundMessage
    {
        return InboundMessage::create(['company_id' => $this->company->id, 'from_phone' => $from, 'type' => 'text', 'body' => $text, ...$extra]);
    }

    public function test_conversations_recognise_the_contact_and_count_unread(): void
    {
        $order = $this->createOrder(['recipient_phone' => '07 11 11 22 22', 'recipient_name' => 'Awa Koné']);
        $this->receive(self::CLIENT, 'Je ne suis pas là avant 17 h');
        $this->receive($this->courierA->user->phone, 'Client injoignable');
        $this->receive('+2250799990000', 'C\'est combien pour Bingerville ?');
        $this->receive('+2250788880000', 'essai', ['simulated' => true]);
        Sanctum::actingAs($this->dispatcher);

        $list = $this->getJson('/api/v1/inbox')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.unread', 3);
        $client = collect($list->json('data'))->firstWhere('contact.phone', self::CLIENT);
        $this->assertSame('client', $client['contact']['kind']);
        $this->assertSame('Awa Koné', $client['contact']['name']);
        $this->assertSame($order->tracking_code, $client['contact']['order']['tracking_code']);
        $this->assertSame('courier', collect($list->json('data'))->firstWhere('contact.phone', $this->courierA->user->phone)['contact']['kind']);

        $this->getJson('/api/v1/inbox?filter=unknown')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inbox?search='.$order->tracking_code)->assertJsonCount(1, 'data');

        // Ouverte : lue
        $this->getJson('/api/v1/inbox/'.urlencode(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.messages');
        $this->getJson('/api/v1/inbox/summary')->assertJsonPath('data.unread', 2);
        $this->getJson('/api/v1/inbox?filter=unread')->assertJsonCount(2, 'data');
    }

    public function test_reply_within_24_hours_with_meta_and_always_with_qr(): void
    {
        $this->whatsapp();
        $this->receive(self::CLIENT, 'Bonjour');
        Sanctum::actingAs($this->dispatcher);

        $this->postJson('/api/v1/inbox/'.urlencode(self::CLIENT).'/reply', ['text' => 'Le livreur passe après 17 h'])->assertCreated()
            ->assertJsonPath('data.messages.1.direction', 'out')->assertJsonPath('data.messages.1.automatic', false);
        $this->assertSame('inbox_reply', OutboundMessage::where('to', self::CLIENT)->value('event'));

        // Plus de 24 h : Meta refuse la réponse libre
        InboundMessage::query()->update(['created_at' => now()->subHours(25)]);
        $this->getJson('/api/v1/inbox/'.urlencode(self::CLIENT))->assertJsonPath('data.window.open', false);
        $this->postJson('/api/v1/inbox/'.urlencode(self::CLIENT).'/reply', ['text' => 'Encore là ?'])->assertUnprocessable();

        // QR code : pas de limite
        WhatsAppAccount::query()->update(['provider' => 'qr']);
        $this->postJson('/api/v1/inbox/'.urlencode(self::CLIENT).'/reply', ['text' => 'Encore là ?'])->assertCreated();
    }

    public function test_simulator_messages_stay_out_of_the_inbox(): void
    {
        app(Conversation::class)->handle($this->company->fresh(), '+2250733334444', 'text', 'Bonjour', null, ['simulated' => true]);
        app(Conversation::class)->handle($this->company->fresh(), '+2250733335555', 'text', 'Bonjour');
        Sanctum::actingAs($this->dispatcher);

        $this->getJson('/api/v1/inbox')->assertJsonCount(1, 'data')->assertJsonPath('data.0.contact.phone', '+2250733335555');
        // La réponse du robot est dans la conversation
        $this->getJson('/api/v1/inbox/'.urlencode('+2250733335555'))->assertJsonPath('data.messages.1.direction', 'out')
            ->assertJsonPath('data.messages.1.automatic', true);
    }

    public function test_couriers_and_merchants_cannot_read_the_inbox(): void
    {
        $this->receive(self::CLIENT, 'Bonjour');

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/inbox')->assertForbidden();
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/inbox/'.urlencode(self::CLIENT))->assertForbidden();
    }
}
