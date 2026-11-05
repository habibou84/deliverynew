<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\LiveAiCard;
use App\Models\LiveCharge;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Live\LiveStudio;
use App\Services\Live\ProductRecognizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class LiveTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // IA simulée : une fiche par photo, sans appel réseau
        $this->app->instance(ProductRecognizer::class, new class extends ProductRecognizer
        {
            public function recognize(UploadedFile $photo): array
            {
                return ['name' => 'Sac banane en cuir, noir', 'description' => 'Sac banane réglable.', 'category' => 'sac banane', 'cost_millis' => 9000];
            }
        });
        Sanctum::actingAs($this->merchantUser);
    }

    private function prepare(): int
    {
        return $this->postJson('/api/v1/live/prepare')->assertOk()->json('data.live.id');
    }

    private function addProduct(int $live, string $name = 'Robe wax', int $price = 9000, int $quantity = 5): array
    {
        return $this->postJson("/api/v1/live/{$live}/products", ['name' => $name, 'price' => $price, 'quantity' => $quantity])->assertCreated()->json('data');
    }

    private function order(int $live, string $code, int $quantity = 1, string $phone = '0707070707'): array
    {
        return $this->postJson("/api/v1/live/{$live}/orders", [
            'code' => $code, 'quantity' => $quantity, 'recipient_name' => 'Awa K.', 'recipient_phone' => $phone,
            'delivery_zone_id' => $this->yopougon->id,
        ])->assertCreated()->json('data');
    }

    public function test_preparer_codes_et_premier_live_offert(): void
    {
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax');
        $data = $this->addProduct($live, 'Sac cuir', 14000, 3);
        $this->assertSame(['A1', 'A2'], array_column($data['items'], 'code'));

        // Plus de 9 produits : la 2e carte prend la lettre B
        app(LiveStudio::class)->updateSettings(['items_per_card' => 1]);
        $rows = array_map(fn ($i) => ['product_id' => $i['product_id']], $data['items']);
        $codes = $this->putJson("/api/v1/live/{$live}/items", ['items' => $rows, 'name' => 'Vendredi mode'])->assertOk()->json('data.items.*.code');
        $this->assertSame(['A1', 'B1'], $codes);

        // Premier live : découverte, rien n'est débité
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.plan', 'trial');
        $this->assertSame(0, MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->count());
    }

    public function test_commandes_stock_liste_d_attente_et_conseils(): void
    {
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 5);
        $this->postJson("/api/v1/live/{$live}/orders", ['code' => 'A1', 'recipient_name' => 'X', 'recipient_phone' => '0707', 'delivery_zone_id' => $this->yopougon->id])
            ->assertStatus(422); // pas encore démarré
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();

        $board = $this->order($live, 'a1', 2);
        $this->assertSame(3, $board['items'][0]['remaining']);
        $this->assertSame(18000, $board['totals']['amount']);
        $order = Order::query()->latest('id')->first();
        $this->assertSame('live', $order->source);
        $this->assertSame(18000, (int) $order->items_amount);
        $this->assertSame('reserved', $order->items()->first()->stock_state);
        $this->assertContains('A1 : il en reste 3. Dites-le !', array_column($board['advices'], 'text'));

        // Plus assez : liste d'attente, pas de course
        $board = $this->order($live, 'A1', 4);
        $this->assertSame(1, $board['totals']['waiting']);
        $this->assertSame(1, Order::query()->count());

        $this->postJson("/api/v1/live/{$live}/orders", ['code' => 'Z9', 'recipient_name' => 'X', 'recipient_phone' => '0707', 'delivery_zone_id' => $this->yopougon->id])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Le code Z9 n\'existe pas dans ce live.');

        $summary = $this->postJson("/api/v1/live/{$live}/end")->assertOk()->json('data');
        $this->assertSame('ended', $summary['live']['status']);
        $this->assertSame(1, $summary['deliveries']['created']);
    }

    public function test_cliente_qui_refuse_souvent(): void
    {
        $live = $this->prepare();
        $this->addProduct($live);
        $this->postJson("/api/v1/live/{$live}/start");
        foreach (range(1, 3) as $i) {
            $this->createOrder(['recipient_phone' => '+2250707070707'])->forceFill(['status' => 'returned'])->save();
        }
        $board = $this->order($live, 'A1', 1, '0707070707');
        $this->assertSame(3, $board['orders'][0]['refusals']);
    }

    public function test_fiches_ia_comptees_et_reprises(): void
    {
        $live = $this->prepare();
        $photo = fn () => UploadedFile::fake()->image('sac.jpg', 400, 400);

        $first = $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk()->json('data');
        $this->assertSame('Sac banane en cuir, noir', $first['name']);
        // Reprise de la photo : même fiche
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo(), 'token' => $first['token']])->assertOk();
        $this->assertSame(1, LiveAiCard::query()->count());
        $this->assertSame(2, LiveAiCard::query()->first()->attempts);

        // Découverte : 5 fiches, puis il faut un pack
        foreach (range(2, 5) as $i) {
            $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk();
        }
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()], ['Accept' => 'application/json'])->assertStatus(422);

        $this->postJson('/api/v1/live/pack')->assertOk()->assertJsonPath('data.extra_cards', 20);
        $this->assertSame(-500, (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount'));
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk()->assertJsonPath('data.cards.left', 19);
    }

    public function test_live_payant_forfait_offert_et_facture(): void
    {
        $studio = app(LiveStudio::class);
        $studio->updateSettings(['trial_lives' => 0, 'agency_share' => 20]);

        $live = $this->prepare();
        $this->addProduct($live);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.plan', 'single');
        $this->assertSame(-1000, (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount'));
        $this->assertSame(800, LiveCharge::query()->first()->platform_amount);
        $this->postJson("/api/v1/live/{$live}/end");

        // Forfait du mois : le live suivant ne coûte rien de plus
        $this->postJson('/api/v1/live/subscribe')->assertOk()->assertJsonPath('data.monthly_cards_left', 60);
        $live2 = $this->prepare();
        $this->addProduct($live2, 'Sac');
        $this->postJson("/api/v1/live/{$live2}/start")->assertJsonPath('data.live.plan', 'monthly');
        $this->assertSame(-6000, (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount'));

        // Part de Jibiat sur la facture de l'entreprise
        $billable = $studio->billable($this->company, CarbonImmutable::now());
        $this->assertSame(800 + 4000, $billable['amount']);

        // L'agence offre le live : rien pour l'e-commerçante, tout pour Jibiat
        $this->company->forceFill(['live_offered' => true])->save();
        $this->merchant->forceFill(['live_plan_until' => null])->save();
        $this->postJson("/api/v1/live/{$live2}/end");
        $live3 = $this->prepare();
        $this->addProduct($live3, 'Parfum');
        $this->postJson("/api/v1/live/{$live3}/start")->assertJsonPath('data.live.plan', 'offered');
        $this->assertSame(-6000, (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount'));
        $this->assertSame(1000, LiveCharge::query()->latest('id')->first()->platform_amount);
    }

    public function test_reglages_console_et_droits(): void
    {
        $this->getJson('/api/v1/console/live')->assertForbidden();
        $live = $this->prepare();
        $this->addProduct($live);

        // Le live d'une autre boutique n'est pas visible
        $merchant2 = Merchant::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->userWithRole(Role::MerchantOwner, ['merchant_id' => $merchant2->id]));
        $this->getJson("/api/v1/live/{$live}")->assertNotFound();

        // Désactivé dans la console : plus de nouveau live
        app(LiveStudio::class)->updateSettings(['enabled' => false]);
        $this->postJson('/api/v1/live/prepare')->assertStatus(422);

        config(['platform.domain' => 'jibiat.test']);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $this->putJson('http://admin.jibiat.test/api/v1/console/live', ['price_live' => 1500, 'enabled' => true])->assertOk()
            ->assertJsonPath('data.settings.price_live', 1500)
            ->assertJsonPath('data.overview.lives', 0);
    }
}
