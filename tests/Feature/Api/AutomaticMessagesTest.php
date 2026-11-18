<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationEvent;
use App\Enums\WhatsAppTemplate;
use App\Models\OutboundMessage;
use App\Services\Messaging\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * WhatsApp › Messages automatiques : une ligne par message, un interrupteur qui décide vraiment de l'envoi.
 */
class AutomaticMessagesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Queue::fake();
        Sanctum::actingAs($this->admin);
    }

    private function row(string $id): array
    {
        return collect($this->getJson('/api/v1/whatsapp/messages')->assertOk()->json('data.messages'))->firstWhere('id', $id);
    }

    public function test_every_template_has_its_line(): void
    {
        $messages = $this->getJson('/api/v1/whatsapp/messages')->assertOk()
            ->assertJsonPath('data.audiences.client', 'Clients')->json('data.messages');

        $listed = collect($messages)->flatMap(fn ($m) => $m['templates'])->countBy();
        foreach (WhatsAppTemplate::cases() as $template) {
            // « Course validée » sert à deux étapes (validation automatique ou par l'agence) ; les autres, une ligne
            $this->assertSame($template === WhatsAppTemplate::OrderConfirmed ? 2 : 1, $listed[$template->value] ?? 0, "Modèle {$template->value} : oublié ou en double");
        }
        $this->assertEqualsCanonicalizing(['client', 'merchant', 'courier', 'team'], collect($messages)->pluck('audience')->unique()->values()->all());
        $this->assertSame('Toujours', $this->row('payment_method')['fixed']);
        $this->assertNull($this->row('payment_method')['on']);
    }

    public function test_each_switch_writes_the_setting_that_decides(): void
    {
        // Étape des e-commerçants, réglage existant, interrupteur par modèle
        $this->putJson('/api/v1/whatsapp/messages/picked_up', ['on' => true])->assertOk()->assertJsonPath('data.on', true);
        $this->assertTrue($this->company->fresh()->merchantWantsByDefault(NotificationEvent::OrderPickedUp));

        $this->putJson('/api/v1/whatsapp/messages/weekly_report', ['on' => false])->assertOk();
        $this->assertFalse($this->company->fresh()->weekly_report);

        $this->putJson('/api/v1/whatsapp/messages/premium', ['on' => false])->assertOk();
        $this->assertFalse($this->company->fresh()->messageOn(WhatsAppTemplate::PremiumCardOffered));
        $this->assertFalse($this->company->fresh()->messageOn(WhatsAppTemplate::PremiumLevelReached));

        // Une ligne sans interrupteur ne se change pas
        $this->putJson('/api/v1/whatsapp/messages/payment_method', ['on' => false])->assertNotFound();
    }

    public function test_shipped_has_its_own_switch_and_a_manual_receipt_still_leaves(): void
    {
        $messenger = app(Messenger::class);
        $this->putJson('/api/v1/whatsapp/messages/delivered', ['on' => false])->assertOk();

        // « Colis expédié » ne suit plus « Colis livré »
        $messenger->toMerchant($this->merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::Shipped, WhatsAppTemplate::Shipped->example());
        $this->assertSame(1, OutboundMessage::where('template_name', 'colis_expedie')->count());
        $this->putJson('/api/v1/whatsapp/messages/shipped', ['on' => false])->assertOk();
        $messenger->toMerchant($this->merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::Shipped, WhatsAppTemplate::Shipped->example());
        $this->assertSame(1, OutboundMessage::where('template_name', 'colis_expedie')->count());

        // Reçu de versement coupé : plus d'envoi automatique, mais « Renvoyer le reçu » part
        $this->putJson('/api/v1/whatsapp/messages/cash_receipt', ['on' => false])->assertOk();
        $phone = $this->courierA->user->phone;
        $this->assertNull($messenger->whatsapp($this->company->id, $phone, 'courier', WhatsAppTemplate::CashReceipt, WhatsAppTemplate::CashReceipt->example()));
        $this->assertNotNull($messenger->whatsapp($this->company->id, $phone, 'courier', WhatsAppTemplate::CashReceipt, WhatsAppTemplate::CashReceipt->example(), ['manual' => true]));
    }

    public function test_only_settings_managers_see_and_change_them(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $this->getJson('/api/v1/whatsapp/messages')->assertForbidden();
        $this->putJson('/api/v1/whatsapp/messages/delivered', ['on' => false])->assertForbidden();
    }
}
