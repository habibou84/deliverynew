<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\RunLegacyOrdersImport;
use App\Jobs\RunLegacyRecheck;
use App\Jobs\SendOutboundMessage;
use App\Jobs\SendWebhook;
use App\Models\CashCollection;
use App\Models\LegacyRecheck;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderAlert;
use App\Services\Legacy\LegacyOrdersSync;
use App\Services\Legacy\LegacyRechecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    private ?int $pageSize = null;

    /** Module d'avant l'ordre « plus récentes d'abord » */
    private bool $oldModule = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Adresse de test (non résolue) : le contrôle de l'adresse est testé dans SecurityLot2Test
        config(['services.webhooks.allow_private_targets' => true]);
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
                'module_version' => $this->oldModule ? 1 : 2,
                'commands' => count($this->commands),
                'unpaid_by_client' => [['client_id' => 7, 'total' => 9000, 'n' => 1]],
                'cash_by_livreur' => [['livreur_id' => 3, 'total' => 10500, 'n' => 1]],
            ]]),
            'products' => Http::response([...$server, 'data' => ($query['after_id'] ?? 0) > 0 ? [] : [
                ['id' => 50, 'client_id' => 7, 'client' => ['id' => 7, 'phone' => $this->merchant->phone], 'name' => 'Basket', 'price' => 5000, 'stock' => 12, 'description' => null, 'variants' => []],
            ], 'next_after_id' => null]),
            default => Http::response([...$server, ...(! $this->oldModule && ($query['order'] ?? null) === 'recent' && ! isset($query['ids']) && ! isset($query['since'])
                ? $this->recentPage($query['after'] ?? null)
                : $this->page(array_values(array_filter($this->commands, fn ($c) => isset($query['ids'])
                    ? in_array($c['id'], array_map('intval', explode(',', $query['ids'])), true)
                    : (isset($query['since']) ? in_array($c['id'], $this->changed, true) : $c['id'] > (int) ($query['after_id'] ?? 0))))))]),
        };
    }

    /** Comme le module : jour de livraison (sinon de création) décroissant, puis numéro décroissant */
    private function recentPage(?string $after): array
    {
        $key = fn (array $c) => ($c['delivery_date'] ?? substr($c['created_at'], 0, 10)).'|'.str_pad((string) $c['id'], 10, '0', STR_PAD_LEFT);
        $rows = array_values($this->commands);
        usort($rows, fn ($a, $b) => strcmp($key($b), $key($a)));
        if ($after !== null) {
            [$day, $id] = explode('|', $after);
            $limit = $day.'|'.str_pad($id, 10, '0', STR_PAD_LEFT);
            $rows = array_values(array_filter($rows, fn ($c) => strcmp($key($c), $limit) < 0));
        }
        $more = $this->pageSize !== null && count($rows) > $this->pageSize;
        $rows = $more ? array_slice($rows, 0, $this->pageSize) : $rows;
        $last = end($rows);

        return ['order' => 'recent', 'data' => $rows, 'next_after' => $more ? ($last['delivery_date'] ?? substr($last['created_at'], 0, 10)).'|'.$last['id'] : null];
    }

    /** Paquets de $pageSize commandes (comme le module), ou tout d'un coup */
    private function page(array $rows): array
    {
        if ($this->pageSize === null || count($rows) <= $this->pageSize) {
            return ['data' => $rows, 'next_after_id' => null];
        }
        $rows = array_slice($rows, 0, $this->pageSize);

        return ['data' => $rows, 'next_after_id' => end($rows)['id']];
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

    public function test_undelivered_orders_wait_for_a_decision_or_their_new_date(): void
    {
        $this->travelTo('2026-10-28 10:00:00');
        $this->commands = [
            111 => $this->command(111, 'undelivered', ['why_undelivered' => 'Client absent']),
            112 => $this->command(112, 'Non livrée', ['relance_date' => '2026-10-30']),
            113 => $this->command(113, 'undelivred', ['livreur_id' => null, 'livreur' => null]),
        ];

        $this->import();

        // Échec avec la raison, colis chez le livreur, file « À décider »
        $failed = $this->order(111);
        $this->assertSame(OrderStatus::DeliveryFailed, $failed->status);
        $this->assertSame($this->courierA->id, $failed->held_by_courier_id);
        $this->assertFalse((bool) $failed->return_requested);
        $this->assertSame(1, $failed->attempts_count);
        $this->assertStringContainsString('Client absent', $failed->events()->where('type', 'incident')->value('note'));

        // Relance prévue : reportée à cette date
        $rescheduled = $this->order(112);
        $this->assertSame(OrderStatus::Rescheduled, $rescheduled->status);
        $this->assertSame('2026-10-30', $rescheduled->delivery_scheduled_date?->toDateString());

        // Sans livreur : au dépôt
        $this->assertSame(OrderStatus::DeliveryFailed, $this->order(113)->status);
        $this->assertNull($this->order(113)->held_by_courier_id);
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
        config(['services.webhooks.allow_private_targets' => false]);
        $this->putJson('/api/v1/company/legacy-orders', ['url' => 'https://127.0.0.1'])->assertStatus(422)->assertJsonValidationErrors('url');
        config(['services.webhooks.allow_private_targets' => true]);
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
        (new RunLegacyOrdersImport($this->company->id, true))->handle(app(LegacyOrdersSync::class));
        $this->getJson('/api/v1/company/legacy-orders')->assertJsonPath('data.job.status', 'done')
            ->assertJsonPath('data.job.report.actions.created', 9);
        $this->putJson('/api/v1/company/legacy-orders/enabled', ['enabled' => true])->assertStatus(422);
        $this->getJson('/api/v1/company/legacy-orders/module')->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-orders')->assertForbidden();
    }

    public function test_an_interrupted_import_resumes_where_it_stopped(): void
    {
        Queue::fake();
        $this->pageSize = 3;
        RunLegacyOrdersImport::$stepSeconds = 0; // un paquet par étape
        $handle = fn () => (new RunLegacyOrdersImport($this->company->id, false))->handle(app(LegacyOrdersSync::class));

        RunLegacyOrdersImport::start($this->company->id, false);
        $handle();
        $state = RunLegacyOrdersImport::state($this->company->id);
        $this->assertSame(['running', 3, 10], [$state['status'], $state['done'], $state['total']]);
        Queue::assertPushed(RunLegacyOrdersImport::class, 2); // étape suivante lancée
        $this->assertSame(2, Order::withoutGlobalScopes()->count(), '110, 109 (e-commerçant inconnu, sautée), 108');

        // Serveur redémarré : l'étape suivante n'a jamais tourné
        $this->travel(11)->minutes();
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-orders')->assertJsonPath('data.job.status', 'stalled')->assertJsonMissingPath('data.job.cursor');
        $this->postJson('/api/v1/company/legacy-orders/run/import')->assertStatus(422);

        // Relancée toute seule, depuis son point d'arrêt
        $this->assertSame(1, RunLegacyOrdersImport::resumeStalled());
        $this->assertSame(0, RunLegacyOrdersImport::resumeStalled(), 'Pas deux fois');
        for ($i = 0; $i < 10 && RunLegacyOrdersImport::state($this->company->id)['status'] === 'running'; $i++) {
            $handle();
        }

        $state = RunLegacyOrdersImport::state($this->company->id);
        $this->assertSame(['done', 10, 9], [$state['status'], $state['done'], $state['report']['actions']['created']]);
        $this->assertSame(9, Order::withoutGlobalScopes()->count(), 'Aucun doublon');
        $this->assertNotNull($this->company->fresh()->legacy_orders_imported_at);
    }

    public function test_the_most_recent_delivery_days_come_first(): void
    {
        $this->pageSize = 4;
        $this->commands[105]['delivery_date'] = '2026-10-20';
        $this->commands[102]['delivery_date'] = '2026-10-25';
        $this->commands[110] = [...$this->commands[110], 'delivery_date' => null, 'created_at' => '2026-10-28 08:00:00'];

        $this->import();

        $order = Order::withoutGlobalScopes()->orderBy('id')->pluck('legacy_id')->all();
        $this->assertSame(['110', '102', '105', '108', '107', '106', '104', '103', '101'], $order);

        // Ancien module (sans cet ordre) : la reprise se fait quand même, par numéro, et l'écran le signale
        $this->oldModule = true;
        Queue::fake();
        RunLegacyOrdersImport::start($this->company->id, true);
        (new RunLegacyOrdersImport($this->company->id, true))->handle(app(LegacyOrdersSync::class));
        $state = RunLegacyOrdersImport::state($this->company->id);
        $this->assertTrue($state['old_module']);
        $this->assertSame(10, $state['done']);
    }

    public function test_replacing_the_old_module_during_the_import_switches_to_the_most_recent_first(): void
    {
        Queue::fake();
        $this->pageSize = 4;
        $this->oldModule = true;
        RunLegacyOrdersImport::$stepSeconds = 0;
        $handle = fn () => (new RunLegacyOrdersImport($this->company->id, false))->handle(app(LegacyOrdersSync::class));
        $this->company->forceFill(['legacy_sync_key' => 'jbr_test'])->save();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/company/legacy-orders/test')->assertOk()->assertJsonPath('data.module_up_to_date', false);

        RunLegacyOrdersImport::start($this->company->id, false);
        $handle();
        $this->assertTrue(RunLegacyOrdersImport::state($this->company->id)['old_module']);

        // Module remplacé dans l'ancienne application : l'étape suivante repart des plus récentes, sans doublon
        $this->oldModule = false;
        $this->postJson('/api/v1/company/legacy-orders/test')->assertOk()->assertJsonPath('data.module_up_to_date', true);
        for ($i = 0; $i < 10 && RunLegacyOrdersImport::state($this->company->id)['status'] === 'running'; $i++) {
            $handle();
        }
        $state = RunLegacyOrdersImport::state($this->company->id);
        $this->assertSame(['done', false, 10], [$state['status'], $state['old_module'], $state['done']]);
        $this->assertSame(9, Order::withoutGlobalScopes()->count());
    }

    protected function tearDown(): void
    {
        RunLegacyOrdersImport::$stepSeconds = 60;
        parent::tearDown();
    }

    private function recheckAll(string $since = '2026-08-01'): LegacyRecheck
    {
        Queue::fake();
        $this->postJson('/api/v1/company/legacy-recheck', ['since' => $since])->assertAccepted();
        $recheck = LegacyRecheck::query()->latest('id')->firstOrFail();
        while ($recheck->fresh()->status === 'running') {
            (new RunLegacyRecheck($recheck->id))->handle(app(LegacyRechecks::class));
        }

        return $recheck->fresh();
    }

    public function test_full_recheck_copies_changes_and_handles_orders_deleted_in_the_old_application(): void
    {
        $this->import();
        Notification::fake();
        Bus::fake([SendOutboundMessage::class, SendWebhook::class]);

        // Terminée ici, modifiée là-bas sans date de mise à jour : relue quand même
        $this->commands[107]['etat'] = 'annule';
        // Effacées : jamais ramassée (annulée), livrée avec argent en jeu (à vérifier), déjà annulée (rien à faire)
        unset($this->commands[101], $this->commands[104], $this->commands[106]);

        Sanctum::actingAs($this->admin);
        $recheck = $this->recheckAll();
        $this->assertSame('done', $recheck->status);
        $this->assertSame([1, 1, 1], [$recheck->counts['deleted_cancelled'], $recheck->counts['deleted_to_check'], $recheck->counts['deleted_closed']]);
        $this->assertGreaterThanOrEqual(1, $recheck->counts['updated']);
        $this->assertSame(OrderStatus::Cancelled, $this->order(107)->status);
        $this->assertSame(OrderStatus::Cancelled, $this->order(101)->status);
        $this->assertNotNull($this->order(101)->legacy_purged_at);
        $this->assertSame(OrderStatus::Delivered, $this->order(104)->status, 'Argent en jeu : pas touchée');
        Notification::assertNothingSent();
        Bus::assertNotDispatched(SendOutboundMessage::class);
        Bus::assertNotDispatched(SendWebhook::class);

        $this->getJson('/api/v1/company/legacy-recheck')->assertOk()->assertJsonPath('data.to_check', 1)
            ->assertJsonPath('data.last.can_undo', true)->assertJsonPath('data.last.counts.deleted_cancelled', 1);
        $this->getJson('/api/v1/company/legacy-recheck/deleted')->assertOk()
            ->assertJsonPath('data.0.legacy_id', '104')->assertJsonPath('data.0.money', true);

        // Annulation : la course revient comme avant, dans les supprimées à vérifier
        $this->postJson("/api/v1/company/legacy-recheck/{$recheck->id}/undo")->assertOk()->assertJsonPath('data.restored', 1);
        $this->assertSame(OrderStatus::Confirmed, $this->order(101)->status);
        $this->getJson('/api/v1/company/legacy-recheck')->assertJsonPath('data.to_check', 2);

        // Décisions : gardée, puis annulée (l'argent pas réglé disparaît)
        $kept = $this->order(101);
        $this->postJson("/api/v1/company/legacy-recheck/deleted/{$kept->id}", ['action' => 'keep'])->assertOk()->assertJsonPath('data.to_check', 1);
        $this->assertSame(OrderStatus::Confirmed, $kept->fresh()->status);
        $gone = $this->order(104);
        $this->postJson("/api/v1/company/legacy-recheck/deleted/{$gone->id}", ['action' => 'cancel'])->assertOk()->assertJsonPath('data.to_check', 0);
        $this->assertSame(OrderStatus::Cancelled, $gone->fresh()->status);
        $this->assertFalse(CashCollection::where('order_id', $gone->id)->exists());
        $this->assertSame(0, MerchantLedgerEntry::where('order_id', $gone->id)->whereNull('payout_id')->count());

        // Une nouvelle recomparaison ne revient pas sur les supprimées déjà traitées
        $this->assertSame(0, $this->recheckAll()->counts['deleted_to_check']);
    }

    public function test_recheck_stops_without_deleting_when_the_old_application_returns_nothing(): void
    {
        $this->import();
        $this->commands = [];
        Sanctum::actingAs($this->admin);

        $recheck = $this->recheckAll();
        $this->assertSame('failed', $recheck->status);
        $this->assertStringContainsString('ne renvoie aucune', $recheck->error);
        $this->assertSame(0, Order::withoutGlobalScopes()->whereNotNull('legacy_deleted_at')->count());
    }

    public function test_nightly_recheck_spots_deleted_orders(): void
    {
        $this->import();
        unset($this->commands[103]);

        app(LegacyOrdersSync::class)->recheck($this->company->fresh());
        $this->assertNotNull($this->order(103)->legacy_deleted_at);
        $this->assertSame('nightly', LegacyRecheck::query()->sole()->source);

        // Rien de supprimé la nuit suivante : pas de trace
        app(LegacyOrdersSync::class)->recheck($this->company->fresh());
        $this->assertSame(1, LegacyRecheck::query()->count());
    }

    public function test_recheck_from_a_date_defaults_to_the_first_launch_of_the_import(): void
    {
        $this->travelTo(now()->parse('2026-10-20 08:00:00'));
        $this->import();
        $this->travelBack();
        // Commande créée après le lancement, puis effacée là-bas
        $this->commands[113] = $this->command(113, 'en cours', ['livreur_id' => null, 'created_at' => '2026-10-25 09:00:00']);
        $this->changed = [113];
        app(LegacyOrdersSync::class)->sync($this->company->fresh());
        unset($this->commands[113], $this->commands[101]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-recheck')->assertOk()
            ->assertJsonPath('data.default_since', '2026-10-20')->assertJsonPath('data.since', '2026-10-20')->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/company/legacy-recheck?since=2026-08-01')->assertJsonPath('data.count', $this->recheckScopeCount());

        // Depuis le lancement : seule la commande récente est relue ; l'ancienne effacée n'est pas vue
        $recheck = $this->recheckAll('2026-10-20');
        $this->assertSame(['2026-10-20', 1, 1], [$recheck->since->toDateString(), $recheck->total, $recheck->counts['deleted_cancelled']]);
        $this->assertSame(OrderStatus::Cancelled, $this->order(113)->status);
        $this->assertNull($this->order(101)->legacy_deleted_at);
        $this->postJson('/api/v1/company/legacy-recheck', ['since' => '2099-01-01'])->assertJsonValidationErrors('since');
    }

    private function recheckScopeCount(): int
    {
        return Order::withoutGlobalScopes()->whereNotNull('legacy_id')->count();
    }

    public function test_recheck_first_brings_orders_created_in_the_old_application_that_never_arrived(): void
    {
        $this->import();
        // Saisie là-bas, jamais copiée (copie de chaque minute en panne) ; une autre modifiée sans être recopiée
        $this->commands[114] = $this->command(114, 'en cours', ['livreur_id' => null, 'created_at' => '2026-10-30 08:00:00']);
        $this->commands[102] = [...$this->commands[102], 'etat' => 'annule', 'updated_at' => '2026-10-30 09:00:00'];
        $this->changed = [102, 114];

        Sanctum::actingAs($this->admin);
        $recheck = $this->recheckAll('2026-08-01');
        $this->assertSame('done', $recheck->status);
        $this->assertSame([2, 1], [$recheck->counts['pulled'], $recheck->counts['created']]);
        $this->assertGreaterThanOrEqual(1, $recheck->counts['updated']);
        $this->assertSame(OrderStatus::Confirmed, $this->order(114)->status);
        $this->assertSame(OrderStatus::Cancelled, $this->order(102)->status);
        // Les nouvelles sont relues avec les autres, des plus récentes aux plus anciennes
        $this->assertSame(Order::withoutGlobalScopes()->whereNotNull('legacy_id')->count(), $recheck->done);
        $this->getJson('/api/v1/company/legacy-recheck')->assertJsonPath('data.last.counts.created', 1)->assertJsonPath('data.last.phase', 'compare');
    }
}
