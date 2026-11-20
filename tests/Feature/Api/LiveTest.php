<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\Live;
use App\Models\LiveAiCard;
use App\Models\LiveCharge;
use App\Models\LiveOrder;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Live\LiveStudio;
use App\Services\Live\ProductRecognizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        // Live payé à la commande, à la fin : rien n'est débité au démarrage
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.plan', 'orders');
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

    public function test_un_seul_produit_avec_variantes_et_je_prends(): void
    {
        $live = $this->prepare();
        $product = $this->addProduct($live, 'Robe wax', 9000, 5)['items'][0]['product_id'];

        // Un seul produit : la cliente écrit « JE PRENDS »
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $board = $this->order($live, 'je prends');
        $this->assertSame('A1', $board['orders'][0]['code']);

        // Un produit retiré mais déjà commandé garde son code : le suivant prend A2
        $codes = $this->putJson("/api/v1/live/{$live}/items", ['items' => [['product_id' => $product, 'variant' => 'S']]])->assertOk()->json('data.items.*.code');
        $this->assertSame(['A1', 'A2'], $codes);
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();

        // Variantes : chaque taille a son code, la course porte la taille
        $live = $this->prepare();
        $this->putJson("/api/v1/live/{$live}/items", ['items' => [
            ['product_id' => $product, 'variant' => 'M', 'price' => 9000, 'quantity' => 2],
            ['product_id' => $product, 'variant' => 'L', 'price' => 10000, 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('data.items.1.code', 'A2')->assertJsonPath('data.items.1.variant', 'L');
        $this->putJson("/api/v1/live/{$live}/items", ['items' => [
            ['product_id' => $product, 'variant' => 'M'], ['product_id' => $product, 'variant' => 'm'],
        ]])->assertStatus(422);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $board = $this->order($live, 'A2');
        $this->assertSame('Robe wax · L', $board['orders'][0]['name']);
        $this->assertSame('Robe wax · L', Order::query()->latest('id')->first()->items()->first()->label);
        // Plusieurs produits : « JE PRENDS » ne suffit pas
        $this->postJson("/api/v1/live/{$live}/orders", ['code' => 'JE PRENDS', 'recipient_name' => 'X', 'recipient_phone' => '0707', 'delivery_zone_id' => $this->yopougon->id])
            ->assertStatus(422);
    }

    public function test_comment_ca_marche_deux_visites_puis_replie(): void
    {
        $this->assertTrue($this->postJson('/api/v1/live/prepare')->json('data.intro.full'));
        $this->postJson('/api/v1/live/intro-seen')->assertOk()->assertJsonPath('data.full', true);
        $this->postJson('/api/v1/live/intro-seen')->assertOk()->assertJsonPath('data.full', false);
        $this->assertFalse($this->postJson('/api/v1/live/prepare')->json('data.intro.full'));
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
        // Fiches offertes au premier live préparé (une seule fois)
        app(LiveStudio::class)->updateSettings(['welcome_cards' => 5]);
        $live = $this->prepare();
        $this->assertSame(5, $this->merchant->refresh()->live_extra_cards);
        $this->prepare();
        $this->assertSame(5, $this->merchant->refresh()->live_extra_cards);
        $photo = fn () => UploadedFile::fake()->image('sac.jpg', 400, 400);

        $first = $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk()->json('data');
        $this->assertSame('Sac banane en cuir, noir', $first['name']);
        // Reprise de la photo : même fiche
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo(), 'token' => $first['token']])->assertOk();
        $this->assertSame(1, LiveAiCard::query()->count());
        $this->assertSame(2, LiveAiCard::query()->first()->attempts);

        // Les 5 fiches offertes, puis il faut un pack (ou remplir la fiche à la main)
        foreach (range(2, 5) as $i) {
            $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk();
        }
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()], ['Accept' => 'application/json'])->assertStatus(422);

        // Pack de commandes (fiches IA incluses) : sans PayDunya, retenu sur le reversement
        $this->postJson('/api/v1/live/orders', ['pack' => 1])->assertOk()->assertJsonPath('data.extra_cards', 100)->assertJsonPath('data.orders_left', 150);
        $this->assertSame(-2500, (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount'));
        $this->post("/api/v1/live/{$live}/recognize", ['photo' => $photo()])->assertOk()->assertJsonPath('data.cards.left', 99);
        $this->postJson('/api/v1/live/orders', ['pack' => 4])->assertJsonValidationErrors('pack');
    }

    /** Réglages pour les tests : commandes offertes au départ, et le reste */
    private function settings(array $more = []): void
    {
        app(LiveStudio::class)->updateSettings($more);
    }

    /** Commandes de clientes différentes (une course chacune) */
    private function orders(int $live, int $count, int $from = 1): void
    {
        foreach (range($from, $from + $count - 1) as $n) {
            $this->order($live, 'A1', 1, '07070707'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
    }

    public function test_live_paye_a_la_commande_illimite_offert_et_facture(): void
    {
        $studio = app(LiveStudio::class);
        $this->settings(['free_orders' => 0, 'agency_share' => 20]);
        $fee = fn () => (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount');

        // Pack de 100 commandes (2 500 F) : sans PayDunya, retenu sur le reversement
        $this->postJson('/api/v1/live/orders', ['pack' => 1])->assertOk()->assertJsonPath('data.orders_left', 100);
        $this->assertSame(-2500, $fee());
        $this->assertSame(2000, LiveCharge::query()->sole()->platform_amount);

        // 3 commandes : retirées du solde à la fin, rien de plus à payer
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 50);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.plan', 'orders');
        $this->orders($live, 3);
        $this->getJson("/api/v1/live/{$live}")->assertJsonPath('data.billing.from_balance', 3)->assertJsonPath('data.billing.left', 97);
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();
        $this->assertSame(-2500, $fee());
        $this->assertSame(97, $this->merchant->refresh()->live_order_credits);
        $this->assertSame([3, 0, 0], array_values(Live::find($live)->only(['billed_orders', 'billed_offered', 'billed_amount'])));

        // Illimité 30 jours : les lives ne sont plus comptés
        $this->postJson('/api/v1/live/subscribe')->assertOk()->assertJsonPath('data.monthly_cards_left', 1000);
        $live2 = $this->prepare();
        $this->addProduct($live2, 'Sac', 9000, 50);
        $this->postJson("/api/v1/live/{$live2}/start")->assertJsonPath('data.live.plan', 'monthly');
        $this->orders($live2, 6);
        $this->postJson("/api/v1/live/{$live2}/end");
        $this->assertSame(-9500, $fee());
        $this->assertSame(97, $this->merchant->refresh()->live_order_credits);

        // Part de Jibiat sur la facture de l'entreprise
        $this->assertSame(2000 + 5600, $studio->billable($this->company, CarbonImmutable::now())['amount']);
    }

    public function test_sans_paydunya_les_commandes_au_dela_du_solde_sont_retenues(): void
    {
        $this->settings(['free_orders' => 2]);
        $fee = fn () => (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount');

        // Sans paiement en ligne, le live démarre même avec un petit solde
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 50);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $this->orders($live, 5);
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();
        // 2 offertes, 3 au prix du petit pack (25 F)
        $this->assertSame(-75, $fee());
        $this->assertSame([5, 2, 75], array_values(Live::find($live)->only(['billed_orders', 'billed_offered', 'billed_amount'])));
        $this->assertSame([0, 0], [$this->merchant->refresh()->live_order_credits, app(LiveStudio::class)->ordersLeft($this->merchant)]);
    }

    public function test_l_agence_qui_offre_les_lives_paie_les_commandes(): void
    {
        $this->settings(['free_orders' => 0]);
        $fee = fn () => (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount');

        // L'agence offre les lives : elle paie les commandes (6 × 25 F), rien pour l'e-commerçante
        $this->company->forceFill(['live_offered' => true])->save();
        $live3 = $this->prepare();
        $this->addProduct($live3, 'Parfum', 9000, 50);
        $this->postJson("/api/v1/live/{$live3}/start")->assertJsonPath('data.live.plan', 'offered');
        $this->orders($live3, 6);
        $this->getJson("/api/v1/live/{$live3}")->assertJsonPath('data.billing.by_agency', true)->assertJsonPath('data.billing.orders', 6);
        $this->postJson("/api/v1/live/{$live3}/end");
        $this->assertSame(0, $fee());
        $charge = LiveCharge::query()->latest('id')->first();
        $this->assertSame([150, true], [$charge->amount, $charge->offered_by_company]);
    }

    public function test_commandes_offertes_puis_solde_et_commande_annulee_comptee(): void
    {
        $this->settings(['free_orders' => 3]);
        $this->merchant->forceFill(['live_order_credits' => 10])->save();
        $fee = fn () => (int) MerchantLedgerEntry::query()->where('type', LedgerEntryType::LiveFee)->sum('amount');

        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 50);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $this->orders($live, 2);
        // Pendant le live : 2 commandes, offertes
        $this->getJson("/api/v1/live/{$live}")->assertJsonPath('data.billing.orders', 2)
            ->assertJsonPath('data.billing.offered', 2)->assertJsonPath('data.billing.from_balance', 0);

        // 6 commandes dont une annulée ensuite : comptée quand même ; 3 offertes, 3 retirées du solde
        $this->orders($live, 4, 3);
        Order::query()->latest('id')->first()->forceFill(['status' => 'cancelled'])->save();
        $this->getJson("/api/v1/live/{$live}")->assertJsonPath('data.billing.from_balance', 3)->assertJsonPath('data.billing.left', 7);
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();
        $this->postJson("/api/v1/live/{$live}/end"); // fin demandée deux fois : comptée une seule fois
        $this->assertSame(7, $this->merchant->refresh()->live_order_credits);
        $this->assertSame(0, $fee());

        $offer = $this->postJson('/api/v1/live/prepare')->json('data.offer');
        $this->assertSame([0, 7, 3], [$offer['free_left'], $offer['orders_left'], $offer['billed_lives'][0]['offered']]);

        // Live oublié ouvert : fermé et compté après 12 h
        $live2 = $this->prepare();
        $this->addProduct($live2, 'Sac', 9000, 50);
        $this->postJson("/api/v1/live/{$live2}/start")->assertOk();
        $this->orders($live2, 2);
        $this->travel(13)->hours();
        $this->assertSame(1, app(LiveStudio::class)->closeStale());
        $this->assertSame('ended', Live::find($live2)->status);
        $this->assertSame(5, $this->merchant->refresh()->live_order_credits);
    }

    public function test_packs_et_50_commandes_offertes_par_defaut(): void
    {
        $offer = $this->postJson('/api/v1/live/prepare')->json('data.offer');
        $this->assertSame([50, 50, 50, 10, 'orders'], [$offer['free_orders'], $offer['free_left'], $offer['orders_left'], $offer['min_orders'], $offer['next_plan']]);
        $this->assertSame([[100, 2500, 100, 25], [300, 6000, 300, 20], [1000, 15000, 1000, 15]], array_map(fn ($p) => [$p['orders'], $p['price'], $p['cards'], $p['unit']], $offer['packs']));
        $this->assertTrue($offer['packs'][1]['advised']);
        $this->assertSame([7000, 25], [$offer['prices']['month'], $offer['unit_price']]);
        $this->assertTrue($offer['can_start']);
    }

    /** Lives payants éteints dans la console : rien n'est compté */
    public function test_lives_gratuits_quand_les_lives_payants_sont_eteints(): void
    {
        $this->settings(['free_orders' => 0, 'paid' => false]);
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 50);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.plan', 'free');
        $this->orders($live, 4);
        $this->postJson("/api/v1/live/{$live}/end");
        $this->assertSame(0, LiveCharge::query()->count());
    }

    public function test_photo_ajoutee_a_un_produit_du_catalogue_sans_photo(): void
    {
        Storage::fake('local');
        $live = $this->prepare();
        $data = $this->addProduct($live);
        $productId = $data['items'][0]['product_id'];
        $this->assertNull($data['items'][0]['photo_url']);

        $url = $this->post("/api/v1/live/products/{$productId}/photo", ['photo' => UploadedFile::fake()->image('robe.jpg', 600, 600)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.photo_url');
        $this->assertStringStartsWith("/produits/{$productId}/photo", $url);
        // La photo reste sur le produit : le live et le catalogue la montrent
        $prep = $this->postJson('/api/v1/live/prepare')->json('data');
        $this->assertSame($url, $prep['items'][0]['photo_url']);
        $this->assertSame($url, collect($prep['catalog'])->firstWhere('id', $productId)['photo_url']);

        // Photo obligatoire, et seulement pour un produit de sa boutique
        $this->postJson("/api/v1/live/products/{$productId}/photo")->assertJsonValidationErrors('photo');
        $other = Merchant::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->userWithRole(Role::MerchantOwner, ['merchant_id' => $other->id]));
        $this->post("/api/v1/live/products/{$productId}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_lien_du_live_les_clientes_commandent_sans_whatsapp(): void
    {
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 2);
        $url = $this->postJson('/api/v1/live/prepare')->json('data.public_url');
        $token = basename($url);
        $this->assertStringContainsString('/l/', $url);

        // Avant le live : produits visibles, commande refusée
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/lives/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.code', 'A1')
            ->assertJsonPath('data.items.0.remaining', 2);
        $form = ['code' => 'A1', 'quantity' => 1, 'name' => 'Awa K.', 'phone' => '0707070707', 'zone_id' => $this->yopougon->id];
        $this->postJson("/api/v1/lives/{$token}/orders", $form)->assertStatus(422);

        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();

        // Pendant le live : la commande arrive chez l'agence, comme une commande WhatsApp
        app('auth')->forgetGuards();
        $this->postJson("/api/v1/lives/{$token}/orders", $form)->assertCreated()->assertJsonPath('data.status', 'ordered');
        $this->assertSame('link', LiveOrder::query()->latest('id')->value('source'));
        $this->assertSame('Awa K.', Order::withoutGlobalScopes()->latest('id')->value('recipient_name'));
        $this->getJson("/api/v1/lives/{$token}")->assertJsonPath('data.items.0.remaining', 1);
        // Code inconnu, et robots
        $this->postJson("/api/v1/lives/{$token}/orders", [...$form, 'code' => 'Z9'])->assertStatus(422);
        $this->postJson("/api/v1/lives/{$token}/orders", [...$form, 'website' => 'x'])->assertStatus(422);
        $this->getJson('/api/v1/lives/inconnu')->assertNotFound();
    }

    public function test_chiffres_des_lives_passes(): void
    {
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 5);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $this->order($live, 'A1', 2);
        $this->order($live, 'A1', 1, '0707070708');
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();

        $data = $this->getJson('/api/v1/live')->assertOk()->json('data');
        $this->assertSame(2, $data['past'][0]['orders_count']);
        $this->assertSame(['lives' => 1, 'orders' => 2], ['lives' => $data['insights']['lives'], 'orders' => $data['insights']['orders']]);
        $this->assertSame(['name' => 'Robe wax', 'quantity' => 3], $data['insights']['top_item']);
        $this->assertIsInt($data['insights']['best_hour']);
    }

    public function test_etiquette_faite_puis_a_refaire_si_les_produits_changent(): void
    {
        $live = $this->prepare();
        $this->postJson("/api/v1/live/{$live}/label-ready")->assertStatus(422);
        $product = $this->addProduct($live, 'Robe wax', 9000, 5)['items'][0]['product_id'];
        $this->assertFalse($this->postJson('/api/v1/live/prepare')->json('data.label_ready'));

        $this->assertTrue($this->postJson("/api/v1/live/{$live}/label-ready")->assertOk()->json('data.label_ready'));
        // Retenue sur le serveur : un autre téléphone la voit cochée
        $this->assertTrue($this->postJson('/api/v1/live/prepare')->json('data.label_ready'));

        // Prix changé : l'étiquette est à refaire
        $this->putJson("/api/v1/live/{$live}/items", ['items' => [['product_id' => $product, 'price' => 8000, 'quantity' => 5]]])->assertOk();
        $this->assertFalse($this->postJson('/api/v1/live/prepare')->json('data.label_ready'));
    }

    public function test_reprendre_les_produits_d_un_live_passe(): void
    {
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 5);
        $this->addProduct($live, 'Sac cuir', 15000, 3);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();

        $draft = $this->postJson('/api/v1/live/prepare')->assertOk()->json('data');
        $this->assertNotSame($live, $draft['live']['id']);
        $this->assertSame(['A1', 'A2'], array_column($draft['past'][0]['items'], 'code'));

        // Un live déjà commencé ne reprend rien ; le live en préparation reprend les produits, prix et quantités
        $this->postJson("/api/v1/live/{$live}/copy/{$live}")->assertStatus(422);
        $items = $this->postJson("/api/v1/live/{$draft['live']['id']}/copy/{$live}")->assertOk()->json('data.items');
        $this->assertSame([['A1', 'Robe wax', 9000, 5], ['A2', 'Sac cuir', 15000, 3]], array_map(fn ($i) => [$i['code'], $i['name'], $i['price'], $i['quantity']], $items));
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
        $this->putJson('http://admin.jibiat.test/api/v1/console/live', ['free_orders' => 400, 'pack2_price' => 6500, 'enabled' => true])->assertOk()
            ->assertJsonPath('data.settings.free_orders', 400)
            ->assertJsonPath('data.settings.pack2_price', 6500)
            ->assertJsonPath('data.overview.lives', 0);
        // Pack sans commandes : refusé
        $this->putJson('http://admin.jibiat.test/api/v1/console/live', ['pack1_orders' => 0])->assertJsonValidationErrors('pack1_orders');
    }

    public function test_la_console_offre_des_commandes_ou_30_jours_et_voit_les_lives_comptes(): void
    {
        $this->settings(['free_orders' => 0]);
        $live = $this->prepare();
        $this->addProduct($live, 'Robe wax', 9000, 50);
        $this->postJson("/api/v1/live/{$live}/start")->assertOk();
        $this->orders($live, 2);
        $this->postJson("/api/v1/live/{$live}/end")->assertOk();

        config(['platform.domain' => 'jibiat.test']);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $console = 'http://admin.jibiat.test/api/v1/console/live';

        // Lives comptés du mois
        $this->getJson("{$console}/payments")->assertOk()
            ->assertJsonPath('data.month.billed.lives', 1)->assertJsonPath('data.month.billed.orders', 2)->assertJsonPath('data.month.billed.amount', 50);

        // Trouver la boutique, puis lui offrir des commandes, 30 jours
        $name = mb_substr($this->merchant->business_name, 0, 5);
        $this->getJson("{$console}/merchants?search=".urlencode($name))->assertOk()->assertJsonPath('data.0.id', $this->merchant->id);
        $this->postJson("{$console}/merchants/{$this->merchant->id}/gift", [])->assertJsonValidationErrors('orders');
        $this->postJson("{$console}/merchants/{$this->merchant->id}/gift", ['orders' => 50])->assertOk()->assertJsonPath('data.free_left', 50);
        $this->postJson("{$console}/merchants/{$this->merchant->id}/gift", ['month' => true])->assertOk();
        $this->assertTrue($this->merchant->refresh()->live_plan_until->isAfter(now()->addDays(29)));
        // Rien n'est encaissé ni facturé
        $this->assertSame(1, LiveCharge::query()->count());

        // L'e-commerçant le voit
        $offer = app(LiveStudio::class)->offer($this->merchant->refresh());
        $this->assertSame([50, 50, 'monthly'], [$offer['free_left'], $offer['orders_left'], $offer['next_plan']]);
    }
}
