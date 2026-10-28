<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\RunLegacyOrdersImport;
use App\Jobs\SendOutboundMessage;
use App\Jobs\SendWebhook;
use App\Models\CashCollection;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderAlert;
use App\Services\Legacy\LegacyOrdersSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Reprise des commandes de l'ancienne application : Jibiat lit le module JibiatSyncController (simulé ici), reprend
 * les commandes sans aucun message, garde les prix, l'argent dû et l'argent chez les livreurs, puis copie les
 * changements ; après la bascule, une course déjà là n'est plus écrasée.
 */
class LegacyOrdersSyncTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const URL = 'https://ancienne.example';

    /** @var array<int, array<string, mixed>> commandes de l'ancienne application */
    private array $commands = [];

    /** @var list<int> numéros renvoyés par la prochaine demande de changements */
    private array $changed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->forceFill(['legacy_id' => '7'])->save();
        $this->courierA->forceFill(['legacy_id' => '3'])->save();
        $this->company->forceFill(['legacy_sync_url' => self::URL, 'legacy_sync_key' => 'jbr_test'])->save();

        $this->commands = [
            101 => $this->command(101, 'en cours', ['livreur_id' => null]),
            102 => $this->command(102, 'récupéré'),
            103 => $this->command(103, 'en chemin'),
            // Livrée, reversement pas fait, argent encore chez le livreur ; remise de 1 000 F sur les articles
            104 => $this->command(104, 'termine', ['remise' => 1000, 'payment' => ['id' => 1, 'command_id' => 104, 'montant' => 9000, 'etat' => 'encours']]),
            // Livrée, reversée et remise à la caisse dans l'ancienne application
            105 => $this->command(105, 'termine', ['cashedback_at' => '2026-09-02 18:00:00', 'payment' => ['id' => 2, 'command_id' => 105, 'montant' => 10000, 'etat' => 'termine']]),
            106 => $this->command(106, 'annule'),
            107 => $this->command(107, 'retour', ['retour_at' => '2026-09-03 10:00:00']),
            108 => $this->command(108, 'retour', ['why_undelivered' => 'Client injoignable']),
            109 => $this->command(109, 'en cours', ['client_id' => 99, 'client' => ['id' => 99, 'nom' => 'Inconnu', 'phone' => '0102030405']]),
            110 => $this->command(110, 'en cours', ['commune' => 'Quartier inconnu', 'livreur_id' => null]),
        ];

        Http::fake(fn ($request) => $this->respond($request));
    }

    private function command(int $id, string $etat, array $extra = []): array
    {
        return [
            'id' => $id, 'etat' => $etat, 'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 12:00:00',
            'client_id' => 7, 'client' => ['id' => 7, 'nom' => 'Boutique Test', 'phone' => $this->merchant->phone],
            'livreur_id' => 3, 'livreur' => ['id' => 3, 'nom' => 'Koffi', 'phone' => $this->courierA->user->phone],
            'nom_client' => "Client {$id}", 'phone' => '0707070707', 'phone2' => null,
            'commune' => 'Yopougon', 'adresse' => 'Siporex', 'lieu' => 'Près de la pharmacie', 'latitude' => null, 'longitude' => null,
            'montant' => 10000, 'remise' => 0, 'livraison' => 1500, 'description' => 'Chaussures', 'observation' => 'Fragile',
            'delivery_date' => '2026-09-01', 'relance_date' => null, 'why_undelivered' => null, 'is_express' => 0,
            'retour_at' => null, 'cashedback_at' => null, 'payment' => null,
            'items' => [['id' => $id, 'command_id' => $id, 'product_id' => 50, 'product_name' => 'Basket', 'qty' => 2, 'price' => 5000]],
            'notes' => [['id' => $id, 'command_id' => $id, 'description' => 'Appeler avant', 'rdv_time' => '14:00:00', 'user_type' => 'admin', 'created_at' => '2026-09-01 10:00:00']],
            'events' => [], 'frais' => [], 'relances' => [],
            ...$extra,
        ];
    }

    private function respond($request)
    {
        if (! str_starts_with($request->url(), self::URL.'/api/jibiat-sync/')) {
            return Http::response([], 404);
        }
        if ($request->header('Authorization')[0] !== 'Bearer jbr_test') {
            return Http::response(['message' => 'Clé de lecture invalide.'], 401);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $resource = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $server = ['server_time' => '2026-10-28 10:00:00'];

        return match ($resource) {
            'summary' => Http::response([...$server, 'data' => [
                'commands' => count($this->commands),
                'unpaid_by_client' => [['client_id' => 7, 'total' => 9000, 'n' => 1]],
                'cash_by_livreur' => [['livreur_id' => 3, 'total' => 10500, 'n' => 1]],
            ]]),
            'products' => Http::response([...$server, 'data' => ($query['after_id'] ?? 0) > 0 ? [] : [
                ['id' => 50, 'client_id' => 7, 'client' => ['id' => 7, 'phone' => $this->merchant->phone], 'name' => 'Basket', 'price' => 5000, 'stock' => 12, 'description' => null, 'variants' => []],
            ], 'next_after_id' => null]),
            default => Http::response([...$server, 'data' => array_values(array_filter($this->commands, fn ($c) => isset($query['ids'])
                ? in_array($c['id'], array_map('intval', explode(',', $query['ids'])), true)
                : (isset($query['since']) ? in_array($c['id'], $this->changed, true) : $c['id'] > (int) ($query['after_id'] ?? 0)))), 'next_after_id' => null]),
        };
    }

    private function import(): array
    {
        return app(LegacyOrdersSync::class)->run($this->company->fresh(), dryRun: false);
    }

    private function order(int $legacyId): Order
    {
        return Order::withoutGlobalScopes()->where('legacy_id', (string) $legacyId)->firstOrFail();
    }

    public function test_simulation_changes_nothing_and_reports_unknown_merchants_and_zones(): void
    {
        $report = app(LegacyOrdersSync::class)->run($this->company->fresh(), dryRun: true);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(9, $report['actions']['created']);
        $this->assertSame(1, $report['actions']['skipped']);
        $this->assertStringContainsString('e-commerçant non repris', $report['skipped'][0]['reason']);
        $this->assertSame(['delivered' => 2], $report['by_etat']['termine']['statuses']);
        $this->assertContains('commune « Quartier inconnu » inconnue : zone « À préciser »', array_column($report['warnings'], 'reason'));
    }

    public function test_import_keeps_states_prices_and_history_without_any_message(): void
    {
        Notification::fake();
        Bus::fake([SendOutboundMessage::class, SendWebhook::class]);

        $this->import();

        $this->assertSame(OrderStatus::Confirmed, $this->order(101)->status);
        $this->assertSame(OrderStatus::PickedUp, $this->order(102)->status);
        $this->assertSame($this->courierA->id, $this->order(102)->held_by_courier_id, 'Colis récupéré : chez le livreur');
        $out = $this->order(103);
        $this->assertSame(OrderStatus::OutForDelivery, $out->status);
        $this->assertSame($this->courierA->id, $out->delivery_courier_id);
        $this->assertSame(OrderStatus::Delivered, $this->order(104)->status);
        $this->assertSame(OrderStatus::Cancelled, $this->order(106)->status);
        $this->assertSame(OrderStatus::Returned, $this->order(107)->status);
        $failed = $this->order(108);
        $this->assertSame(OrderStatus::DeliveryFailed, $failed->status);
        $this->assertTrue($failed->return_requested);

        // Prix gardés : articles = montant − remise, livraison payée par le client
        $delivered = $this->order(104);
        $this->assertSame(9000, $delivered->items_amount);
        $this->assertSame(1500, $delivered->delivery_fee);
        $this->assertSame(10500, $delivered->cod_amount);
        $this->assertSame('Yopougon', $delivered->deliveryZone->name);
        $this->assertSame('legacy', $delivered->source);
        $this->assertSame(1, $delivered->items()->count());
        $this->assertStringContainsString('Appeler avant (rendez-vous 14:00:00)', $delivered->events()->where('type', 'note')->first()->note);

        // Commune inconnue : zone « À préciser » ; e-commerçant inconnu : pas repris
        $this->assertSame('À préciser', $this->order(110)->deliveryZone->name);
        $this->assertNull(Order::withoutGlobalScopes()->where('legacy_id', '109')->first());

        // Aucun message ni alerte, la copie en continu est prête
        Notification::assertNothingSent();
        Bus::assertNotDispatched(SendOutboundMessage::class);
        Bus::assertNotDispatched(SendWebhook::class);
        $this->assertTrue($this->company->fresh()->legacy_sync_enabled);
        $this->assertSame('2026-10-28 10:00:00', $this->company->fresh()->legacy_sync_cursor);

        // Produits et stock actuel ; article relié au produit repris
        $this->assertSame(12, Product::withoutGlobalScopes()->where('legacy_id', '50')->sole()->levels()->sum('on_hand'));
    }

    public function test_money_owed_to_merchants_and_cash_held_by_couriers_match_the_old_application(): void
    {
        $this->import();

        // Reversement « encours » : dû à l'e-commerçant (son paiement fait foi), argent encore chez le livreur
        $owed = $this->order(104);
        $this->assertSame(9000, (int) MerchantLedgerEntry::where('order_id', $owed->id)->whereNull('payout_id')->sum('amount'));
        $this->assertTrue(CashCollection::where('order_id', $owed->id)->inCourierHands()->exists());
        // Reversé et remis à la caisse : rien de dû, rien chez le livreur
        $settled = $this->order(105);
        $this->assertSame(0, MerchantLedgerEntry::where('order_id', $settled->id)->count());
        $this->assertFalse(CashCollection::where('order_id', $settled->id)->inCourierHands()->exists());

        $balances = app(LegacyOrdersSync::class)->balances($this->company->fresh());
        $this->assertSame(['merchants_old' => 9000, 'merchants_jibiat' => 9000, 'couriers_old' => 10500, 'couriers_jibiat' => 10500, 'merchants_gaps' => 0, 'couriers_gaps' => 0], $balances['totals']);

        // Relancer : rien ne change, rien n'est compté deux fois
        $report = $this->import();
        $this->assertSame(9, $report['actions']['unchanged']);
        $this->assertSame(9000, (int) MerchantLedgerEntry::where('order_id', $owed->id)->whereNull('payout_id')->sum('amount'));
    }

    public function test_changes_are_copied_and_a_payment_made_in_the_old_application_settles_the_course(): void
    {
        $this->import();

        // En chemin → livrée, reversement « encours » puis reversée et argent remis
        $this->commands[103] = [...$this->commands[103], 'etat' => 'termine', 'updated_at' => '2026-10-28 10:05:00',
            'payment' => ['id' => 3, 'command_id' => 103, 'montant' => 10000, 'etat' => 'encours']];
        $this->commands[111] = $this->command(111, 'en cours', ['livreur_id' => null, 'created_at' => '2026-10-28 10:06:00']);
        $this->changed = [103, 111];
        $counts = app(LegacyOrdersSync::class)->sync($this->company->fresh());

        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'conflict' => 0], $counts);
        $order = $this->order(103);
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(10000, (int) MerchantLedgerEntry::where('order_id', $order->id)->whereNull('payout_id')->sum('amount'));

        $this->commands[103]['payment']['etat'] = 'termine';
        $this->commands[103]['cashedback_at'] = '2026-10-28 18:00:00';
        $this->changed = [103];
        app(LegacyOrdersSync::class)->sync($this->company->fresh());

        $this->assertSame(0, (int) MerchantLedgerEntry::where('order_id', $order->id)->whereNull('payout_id')->sum('amount'));
        $this->assertFalse(CashCollection::where('order_id', $order->id)->inCourierHands()->exists());
        $this->assertStringStartsWith('ANC-', MerchantLedgerEntry::where('order_id', $order->id)->where('type', 'payout')->sole()->payout->reference);
        $this->assertSame(1, $this->company->fresh()->legacy_sync_stats['created']);
    }

    public function test_after_the_switch_jibiat_is_the_master_and_new_orders_reach_the_team(): void
    {
        $this->import();
        $this->company->forceFill(['legacy_switch_at' => now()->subMinute()])->save();
        Notification::fake();

        $this->commands[102] = [...$this->commands[102], 'etat' => 'annule', 'updated_at' => '2026-10-28 11:00:00'];
        $this->commands[112] = $this->command(112, 'en cours', ['livreur_id' => null]);
        $this->changed = [102, 112];
        $counts = app(LegacyOrdersSync::class)->sync($this->company->fresh());

        $this->assertSame(1, $counts['conflict']);
        $kept = $this->order(102);
        $this->assertSame(OrderStatus::PickedUp, $kept->status, 'Déjà dans Jibiat : pas écrasée');
        $this->assertNotNull($kept->legacy_conflict_at);
        $this->assertSame(OrderStatus::Confirmed, $this->order(112)->status);
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'new_order' && $n->order->legacy_id === '112');
    }

    public function test_nightly_recheck_reads_courses_still_in_progress(): void
    {
        $this->import();
        $this->commands[101]['etat'] = 'annule';

        $this->assertGreaterThan(0, app(LegacyOrdersSync::class)->recheck($this->company->fresh()));
        $this->assertSame(OrderStatus::Cancelled, $this->order(101)->status);
    }

    public function test_settings_key_simulation_and_errors(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/company/legacy-orders', ['url' => 'http://pas-https.example'])->assertStatus(422);
        $this->putJson('/api/v1/company/legacy-orders', ['url' => 'https://127.0.0.1'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->putJson('/api/v1/company/legacy-orders', ['url' => self::URL.'/'])->assertOk()->assertJsonPath('data.url', self::URL);
        $key = $this->postJson('/api/v1/company/legacy-orders/key')->assertCreated()->json('data.key');
        $this->assertStringStartsWith('jbr_', $key);
        $this->assertNotSame($key, $this->company->fresh()->getRawOriginal('legacy_sync_key'), 'Clé chiffrée en base');

        // Nouvelle clé pas encore recopiée dans l'ancienne application : refusée
        $this->postJson('/api/v1/company/legacy-orders/test')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'JIBIAT_SYNC_KEY'));
        $this->company->fresh()->forceFill(['legacy_sync_key' => 'jbr_test'])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->postJson('/api/v1/company/legacy-orders/test')->assertOk()->assertJsonPath('data.commands', 10);

        Queue::fake();
        $this->postJson('/api/v1/company/legacy-orders/run/simulate')->assertAccepted()->assertJsonPath('data.job.status', 'running');
        Queue::assertPushed(RunLegacyOrdersImport::class, fn ($job) => $job->dryRun);
        $this->postJson('/api/v1/company/legacy-orders/run/import')->assertStatus(422);

        // Tâche de fond : rapport gardé pour l'écran
        Cache::forget(RunLegacyOrdersImport::key($this->company->id));
        (new RunLegacyOrdersImport($this->company->id, true))->handle(app(LegacyOrdersSync::class));
        $this->getJson('/api/v1/company/legacy-orders')->assertJsonPath('data.job.status', 'done')
            ->assertJsonPath('data.job.report.actions.created', 9);
        $this->putJson('/api/v1/company/legacy-orders/enabled', ['enabled' => true])->assertStatus(422);
        $this->getJson('/api/v1/company/legacy-orders/module')->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-orders')->assertForbidden();
    }
}
