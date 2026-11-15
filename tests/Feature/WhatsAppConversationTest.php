<?php

namespace Tests\Feature;

use App\Enums\PlatformFeature;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\Company;
use App\Models\InboundMessage;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppSession;
use App\Models\Zone;
use App\Services\Ai\JibiatAi;
use App\Services\WhatsApp\ClaudeOrderParser;
use App\Services\WhatsApp\Conversation;
use App\Services\WhatsApp\HeuristicOrderParser;
use App\Services\WhatsApp\OrderMessageParser;
use App\Services\WhatsApp\WhatsAppMedia;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class WhatsAppConversationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const MERCHANT_PHONE = '+2250501020304';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => self::MERCHANT_PHONE]);
    }

    /**
     * Envoie un message au bot et renvoie ses réponses (texte et boutons).
     *
     * @return list<array{text: string, buttons: array<string, string>}>
     */
    private function say(?string $text, ?string $button = null, string $from = self::MERCHANT_PHONE): array
    {
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), $from, $button ? 'button' : 'text', $text, $button);

        return OutboundMessage::where('id', '>', $lastId)->orderBy('id')->get()
            ->map(fn ($m) => ['text' => $m->body, 'buttons' => $m->payload['buttons'] ?? []])->all();
    }

    private function lastReply(?string $text, ?string $button = null): array
    {
        $replies = $this->say($text, $button);
        $this->assertNotEmpty($replies);

        return end($replies);
    }

    public function test_unknown_numbers_are_turned_away(): void
    {
        $reply = $this->say('Bonjour', null, '+2250799999999')[0];

        $this->assertStringContainsString("n'est pas enregistré", $reply['text']);
        $this->assertSame(0, WhatsAppSession::count());
        $this->assertSame(1, InboundMessage::whereNull('merchant_id')->count());
    }

    public function test_greeting_shows_the_menu(): void
    {
        $reply = $this->lastReply('Bonjour');

        $this->assertStringContainsString('Boutique Test', $reply['text']);
        $this->assertSame(['new', 'point', 'track'], array_keys($reply['buttons']));
        $this->assertSame('conversation', OutboundMessage::latest('id')->first()->event);
    }

    public function test_structured_message_becomes_an_order_after_confirmation(): void
    {
        $this->lastReply(null, 'new');

        $recap = $this->lastReply("Nom : Awa Koné\nTél : 07 08 09 10 11\nCommune : Yopougon\nAdresse : Siporex\nRepère : face pharmacie\nArticle : 2 pagnes\nMontant : 15 000");

        $this->assertStringContainsString('Récapitulatif', $recap['text']);
        $this->assertStringContainsString('Awa Koné · 07 08 09 10 11', $recap['text']);
        $this->assertStringContainsString('Yopougon — Siporex', $recap['text']);
        $this->assertStringContainsString('Livraison : 1 500 F', $recap['text']);
        $this->assertSame(['confirm', 'edit', 'cancel'], array_keys($recap['buttons']));

        $done = $this->lastReply('✅ Confirmer', 'confirm');

        $order = Order::sole();
        $this->assertSame('whatsapp', $order->source);
        $this->assertSame(['Awa Koné', '+2250708091011', 15000, 'Siporex', 'face pharmacie', '2 pagnes'],
            [$order->recipient_name, $order->recipient_phone, $order->items_amount, $order->delivery_address, $order->delivery_landmark, $order->description]);
        $this->assertSame($this->yopougon->id, $order->delivery_zone_id);
        $this->assertStringContainsString($order->tracking_code, $done['text']);
        $this->assertSame($order->id, InboundMessage::latest('id')->first()->order_id);
        $this->assertSame('idle', WhatsAppSession::sole()->state);
    }

    public function test_missing_information_is_asked_one_question_at_a_time(): void
    {
        $this->assertStringContainsString('numéro du client', $this->lastReply('Une livraison à Yopougon svp')['text']);
        $this->assertArrayNotHasKey('delivery_address', WhatsAppSession::sole()->draft);
        $this->assertStringContainsString('Combien', $this->lastReply('07 08 09 10 11')['text']);

        $recap = $this->lastReply('déjà payé');
        $this->assertStringContainsString('Articles : 0 F', $recap['text']);

        $this->lastReply('oui');
        $this->assertSame(0, Order::sole()->items_amount);
    }

    public function test_forwarded_customer_message_is_understood(): void
    {
        $recap = $this->lastReply("Bonsoir, c'est pour Awa, elle est à Yopougon Siporex face à la pharmacie du marché. "
            .'Son numéro 07 08 09 10 11, total 12 500 F, livraison à la charge du client');

        $this->assertStringContainsString('Awa · 07 08 09 10 11', $recap['text']);
        $this->assertStringContainsString('Yopougon — Siporex', $recap['text']);
        $this->assertStringContainsString('Articles : 12 500 F', $recap['text']);
        $this->assertStringContainsString('payée par le client', $recap['text']);
        $this->assertStringContainsString('Le client paiera : 14 000 F', $recap['text']);
        $this->assertStringContainsString('Repère : face à la pharmacie du marché', $recap['text']);
    }

    public function test_ambiguous_zone_is_chosen_with_buttons_and_corrections_update_the_recap(): void
    {
        $r2 = $this->zone('Riviera 2', $this->cocody);
        $this->zone('Riviera 3', $this->cocody);
        $this->rule($this->grid, $this->cocody, $r2, 1200);

        $choice = $this->lastReply("Tél : 0708091011\nCommune : riviera\nMontant : 8000");
        $this->assertSame(['zone:'.$r2->id, 'zone:'.($r2->id + 1)], array_keys($choice['buttons']));

        $recap = $this->lastReply('Riviera 2', 'zone:'.$r2->id);
        $this->assertStringContainsString('Cocody › Riviera 2', $recap['text']);
        $this->assertStringContainsString('Livraison : 1 200 F', $recap['text']);

        $corrected = $this->lastReply('montant 9500');
        $this->assertStringContainsString('Articles : 9 500 F', $corrected['text']);

        $this->assertStringContainsString('annulée', $this->lastReply('annuler')['text']);
        $this->assertSame(0, Order::count());
    }

    public function test_tracking_and_daily_point(): void
    {
        $order = $this->createOrder(['items_amount' => 5000]);

        $tracking = $this->lastReply("Où en est {$order->tracking_code} ?");
        $this->assertStringContainsString($order->tracking_code, $tracking['text']);
        $this->assertStringContainsString('À encaisser : 5 000 F', $tracking['text']);

        $point = $this->lastReply(null, 'point');
        $this->assertStringContainsString('Point du jour', $point['text']);
        $this->assertStringContainsString('1 courses', $point['text']);
    }

    public function test_conversation_expires_and_can_be_disabled(): void
    {
        $this->lastReply('Une livraison à Yopougon');
        $this->assertSame('draft', WhatsAppSession::sole()->state);

        $this->travel(31)->minutes();
        $this->assertStringContainsString('Que voulez-vous faire', $this->lastReply('menu')['text']);
        $this->assertNull(WhatsAppSession::sole()->draft);

        $this->company->update(['whatsapp_orders' => false]);
        $this->assertStringContainsString("n'est pas activée", $this->lastReply('Bonjour')['text']);
    }

    public function test_meta_webhook_delivers_messages_once(): void
    {
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '1098765', 'status' => 'connected']);

        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1098765'],
            'messages' => [['id' => 'wamid.IN1', 'from' => '2250501020304', 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
        ]]]]]];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame(1, InboundMessage::count());
        $this->assertSame($this->merchant->id, InboundMessage::sole()->merchant_id);
        $this->assertSame(1, OutboundMessage::where('event', 'conversation')->count());

        // Réponse à un bouton
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'wamid.IN2', 'from' => '2250501020304', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'new', 'title' => '📦 Nouvelle course']],
        ];
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->assertSame('draft', WhatsAppSession::sole()->state);
        // Photo : sa légende comme texte, le fichier à télécharger chez Meta
        Queue::fake();
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'wamid.IN3', 'from' => '2250501020304', 'type' => 'image', 'image' => ['id' => 'media-9', 'mime_type' => 'image/jpeg', 'caption' => 'Commande de Mme Koné'],
        ];
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        Queue::assertPushed(ProcessInboundWhatsApp::class, fn ($job) => $job->type === 'image' && $job->text === 'Commande de Mme Koné' && $job->meta['media'] === ['id' => 'media-9']);
    }

    public function test_simulator_is_for_admins(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/whatsapp/simulate', ['from' => self::MERCHANT_PHONE, 'text' => 'Bonjour'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/whatsapp/simulate', ['from' => self::MERCHANT_PHONE, 'text' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('data.merchant_id', $this->merchant->id)
            ->assertJsonPath('data.replies.0.buttons.0.id', 'new');
    }

    public function test_claude_answers_are_validated_and_completed_by_the_rules(): void
    {
        $parser = new class(app(HeuristicOrderParser::class), app(ZoneMatcher::class)) extends ClaudeOrderParser
        {
            public ?array $answer = null;

            public int $calls = 0;

            protected function extract(array $content, Collection $zones, Company $company, string $feature): ?array
            {
                $this->calls++;

                return $this->answer;
            }
        };
        // Points d'IA dans la formule de l'entreprise
        $plan = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'features' => [], 'ai_points' => 100]);
        $this->company->forceFill(['plan_id' => $plan->id])->save();

        $zones = Zone::forCompany($this->company->id)->with('parent')->get();
        $parser->answer = [
            'recipient_name' => 'Mme Bamba', 'recipient_phone' => 'pas un numéro', 'recipient_phone2' => null,
            'zone_name' => 'Cocody › Angré', 'delivery_address' => '8e tranche', 'delivery_landmark' => null,
            'items_amount' => 22000, 'fee_payer' => 'recipient', 'description' => 'robe', 'merchant_note' => null,
        ];

        $result = $parser->parse('Mme Bamba 07 08 09 10 11 angré 8e tranche robe 22k livraison client', $zones, self::MERCHANT_PHONE);

        $this->assertSame('Mme Bamba', $result['recipient_name']);
        $this->assertSame('+2250708091011', $result['recipient_phone']); // numéro invalide de Claude : celui des règles
        $this->assertSame([$this->angre->id], $result['zones']);
        $this->assertSame(22000, $result['items_amount']);
        $this->assertSame('recipient', $result['fee_payer']);

        // Refus ou indisponibilité : analyse par règles seule
        $parser->answer = null;
        $this->assertSame([$this->angre->id], $parser->parse('07 08 09 10 11 Angré 5000 F', $zones)['zones']);
        $this->assertSame(2, $parser->calls);

        // Formule sans points d'IA : l'IA n'est pas appelée, analyse par règles
        $plan->update(['ai_points' => 0]);
        $this->assertSame([$this->angre->id], $parser->parse('07 08 09 10 11 Angré 5000 F', $zones)['zones']);
        $this->assertSame(2, $parser->calls);
        $this->assertInstanceOf(HeuristicOrderParser::class, app(OrderMessageParser::class));
    }

    /** Lecteur IA dont la réponse est fixée ; chaque lecture brûle ses points comme un vrai appel */
    private function photoReader(?array $answer): ClaudeOrderParser
    {
        $parser = new class(app(HeuristicOrderParser::class), app(ZoneMatcher::class)) extends ClaudeOrderParser
        {
            public ?array $answer = null;

            public array $seen = [];

            protected function extract(array $content, Collection $zones, Company $company, string $feature): ?array
            {
                $this->seen[] = [$feature, $content[0]['type']];
                app(JibiatAi::class)->record($company, $feature, 'claude-haiku-5-5', 1500, 200);

                return $this->answer;
            }
        };
        $parser->answer = $answer;
        $this->app->instance(OrderMessageParser::class, $parser);

        return $parser;
    }

    /** Photo reçue par le numéro relié par QR code (déposée par le service whatsapp-bridge) */
    private function sendPhoto(?string $caption = null): string
    {
        $dir = WhatsAppMedia::bridgeDir();
        @mkdir($dir, 0777, true);
        $file = 'test-'.uniqid().'.jpg';
        file_put_contents("{$dir}/{$file}", "\xFF\xD8\xFF\xE0photo");
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), self::MERCHANT_PHONE, 'image', $caption, null, ['media' => ['file' => $file]]);
        $this->lastPhotoReplies = OutboundMessage::where('id', '>', $lastId)->orderBy('id')->pluck('body')->all();

        return $file;
    }

    private array $lastPhotoReplies = [];

    public function test_a_photo_of_an_order_is_read_by_the_ai_for_two_points(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'features' => PlatformFeature::values(), 'ai_points' => 10]);
        $this->company->forceFill(['plan_id' => $plan->id])->save();
        $parser = $this->photoReader([
            'recipient_name' => 'Mme Bamba', 'recipient_phone' => '0708091011', 'recipient_phone2' => null,
            'zone_name' => 'Cocody › Angré', 'delivery_address' => '8e tranche', 'delivery_landmark' => null,
            'items_amount' => 22000, 'fee_payer' => 'recipient', 'description' => 'robe', 'merchant_note' => null,
        ]);

        $file = $this->sendPhoto();

        $this->assertSame([['photos', 'image']], $parser->seen);
        $this->assertFileDoesNotExist(WhatsAppMedia::bridgeDir().'/'.$file);
        $draft = WhatsAppSession::sole()->draft;
        $this->assertSame(['Mme Bamba', '+2250708091011', $this->angre->id, 22000], [$draft['recipient_name'], $draft['recipient_phone'], $draft['zone_id'], $draft['items_amount']]);
        $this->assertSame(2, app(JibiatAi::class)->pointsUsed($this->company->fresh()));

        // Rien de lisible sur la photo
        $parser->answer = ['recipient_name' => null, 'recipient_phone' => null, 'recipient_phone2' => null, 'zone_name' => null, 'delivery_address' => null,
            'delivery_landmark' => null, 'items_amount' => null, 'fee_payer' => null, 'description' => null, 'merchant_note' => null];
        WhatsAppSession::query()->delete();
        $this->sendPhoto();
        $this->assertStringContainsString('pas trouvé de commande sur cette photo', end($this->lastPhotoReplies));
    }

    public function test_without_ai_points_a_photo_is_not_read_but_its_caption_is(): void
    {
        $plan = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'features' => PlatformFeature::values(), 'ai_points' => 0]);
        $this->company->forceFill(['plan_id' => $plan->id])->save();
        $parser = $this->photoReader(null);

        $this->sendPhoto();
        $this->assertSame([], $parser->seen);
        $this->assertStringContainsString('Je ne peux pas lire les photos', end($this->lastPhotoReplies));

        // La légende est lue comme un message écrit (par règles : pas de points)
        $this->sendPhoto('07 08 09 10 11 Angré 5000 F');
        $this->assertSame([], $parser->seen);
        $this->assertSame($this->angre->id, WhatsAppSession::sole()->draft['zone_id']);
    }

    public function test_a_photo_sent_to_a_meta_number_is_downloaded_from_meta(): void
    {
        config(['messaging.whatsapp.graph_url' => 'https://graph.test']);
        $account = WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '5551', 'access_token' => 'jeton', 'status' => 'connected']);
        Http::fake([
            'graph.test/*/media-42' => Http::response(['url' => 'https://cdn.test/fichier', 'mime_type' => 'image/jpeg']),
            'cdn.test/*' => Http::response("\xFF\xD8photo", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->assertSame(["\xFF\xD8photo", 'image/jpeg'], app(WhatsAppMedia::class)->photo(['id' => 'media-42'], $account->id));
        Http::assertSent(fn ($r) => $r->url() === 'https://cdn.test/fichier' && $r->hasHeader('Authorization', 'Bearer jeton'));
        // Un nom de fichier du service QR ne peut pas sortir de son dossier
        $this->assertNull(app(WhatsAppMedia::class)->photo(['file' => '../../.env'], null));
    }
}
