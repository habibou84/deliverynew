<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\LiveCharge;
use App\Models\LivePayment;
use App\Models\MerchantLedgerEntry;
use App\Models\Plan;
use App\Models\PlatformCredit;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Services\Live\LiveStudio;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Outil live payé en ligne (PayDunya) par l'e-commerçant, directement à Jibiat : le live démarre une fois le
 * paiement relu chez PayDunya, et la part de l'agence est déduite de sa facture (le reste est reporté).
 */
class LivePaymentTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $status = 'pending';

    private int $invoices = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Abidjan'));
        $this->buildWorld();
        config(['payments.paydunya' => [...config('payments.paydunya'), 'master_key' => 'cle-principale', 'private_key' => 'cle-privee', 'token' => 'jeton', 'mode' => 'test']]);
        // Plus d'essai gratuit ; l'agence touche 20 % des lives de ses e-commerçants
        app(LiveStudio::class)->updateSettings(['trial_lives' => 0, 'price_live' => 1000, 'agency_share' => 20, 'pack_price' => 500, 'pack_cards' => 20]);
        Http::fake([
            '*/sandbox-api/v1/checkout-invoice/create' => function () {
                $token = 'tok-'.(++$this->invoices);

                return Http::response(['response_code' => '00', 'response_text' => "https://paydunya.com/sandbox-checkout/invoice/{$token}", 'token' => $token]);
            },
            '*/sandbox-api/v1/checkout-invoice/confirm/*' => fn () => Http::response(['response_code' => '00', 'status' => $this->status, 'invoice' => ['total_amount' => 1000]]),
        ]);
        Sanctum::actingAs($this->merchantUser);
    }

    private function ipn(string $hash, string $token = 'tok-1'): void
    {
        app('auth')->forgetGuards();
        $this->post('/api/webhooks/paydunya', ['data' => ['hash' => $hash, 'status' => 'completed', 'invoice' => ['token' => $token]]]);
        Sanctum::actingAs($this->merchantUser);
    }

    public function test_the_live_starts_once_paid_online_and_the_agency_share_goes_on_its_credit(): void
    {
        $live = $this->postJson('/api/v1/live/prepare')->assertOk()->assertJsonPath('data.offer.pay_online', true)->json('data.live.id');
        $this->postJson("/api/v1/live/{$live}/products", ['name' => 'Robe wax', 'price' => 9000, 'quantity' => 3])->assertCreated();

        // Démarrer : d'abord la page de paiement de PayDunya
        $data = $this->postJson("/api/v1/live/{$live}/start")->assertOk()->json('data');
        $this->assertSame('https://paydunya.com/sandbox-checkout/invoice/tok-1', $data['payment_url']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'checkout-invoice/create') && $r['invoice']['total_amount'] === 1000
            && $r->hasHeader('PAYDUNYA-MASTER-KEY', 'cle-principale') && str_ends_with($r['actions']['callback_url'], '/api/webhooks/paydunya'));
        $this->assertSame('draft', $this->getJson("/api/v1/live/{$live}")->json('data.live.status'));

        // Retour sur l'application, pas encore payé
        $this->getJson("/api/v1/live/payments/{$data['payment_id']}")->assertOk()->assertJsonPath('data.status', 'pending');

        // Avis falsifié : refusé
        $this->ipn('faux');
        $this->assertSame(LivePayment::PENDING, LivePayment::first()->status);

        // Payé : avis authentique (empreinte de la clé principale), relu chez PayDunya
        $this->status = 'completed';
        $this->ipn(hash('sha512', 'cle-principale'));
        $this->ipn(hash('sha512', 'cle-principale')); // avis reçu deux fois : appliqué une seule fois
        $this->getJson("/api/v1/live/payments/{$data['payment_id']}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.live_status', 'live');

        $charge = LiveCharge::sole();
        $this->assertSame(['paydunya', 1000, 800], [$charge->paid_via, $charge->amount, $charge->platform_amount]);
        // Rien retenu sur les reversements de l'e-commerçant
        $this->assertSame(0, MerchantLedgerEntry::query()->where('merchant_id', $this->merchant->id)->count());
        // 20 % pour l'agence, sur son crédit
        $this->assertSame(200, PlatformCredit::balance($this->company->id));

        // Paquet de fiches IA : aussi en ligne
        $this->postJson('/api/v1/live/pack')->assertOk()->assertJsonStructure(['data' => ['payment_url', 'payment_id']]);
    }

    public function test_the_credit_is_deducted_from_the_invoice_and_the_rest_is_carried_over(): void
    {
        Notification::fake();
        $plan = Plan::create(['name' => 'Essentiel', 'price_monthly' => 300, 'included_orders' => null, 'overage_per_order' => 0, 'max_couriers' => 5, 'features' => []]);
        $this->company->forceFill(['plan_id' => $plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
        // Crédit gagné : 500 F (plus que la facture du mois)
        PlatformCredit::create(['company_id' => $this->company->id, 'amount' => 500, 'reason' => PlatformCredit::EARNED, 'label' => 'Boutique Test · Live']);
        // Un live payé en ligne n'est pas facturé à l'agence
        LiveCharge::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'kind' => 'live', 'label' => 'Live', 'amount' => 1000, 'platform_amount' => 800, 'cards' => 10, 'paid_via' => 'paydunya']);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Africa/Abidjan'));
        $invoice = app(Billing::class)->invoiceFor($this->company->fresh(), '2026-11');

        $this->assertSame([0, 300, 0], [$invoice->live_amount, $invoice->credit_amount, $invoice->total]);
        $this->assertSame(PlatformInvoice::PAID, $invoice->status);
        // Reste 200 F de crédit pour la facture suivante
        $this->assertSame(200, PlatformCredit::balance($this->company->id));
    }

    public function test_the_console_follows_payments_and_records_a_payout(): void
    {
        PlatformCredit::create(['company_id' => $this->company->id, 'amount' => 700, 'reason' => PlatformCredit::EARNED, 'label' => 'Boutique Test · Live']);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);

        $this->getJson('/api/v1/console/live/payments')->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.credits.0.balance', 700);
        $this->postJson("/api/v1/console/live/credits/{$this->company->id}/payout", ['amount' => 900])->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/console/live/credits/{$this->company->id}/payout", ['amount' => 500])->assertOk()
            ->assertJsonPath('data.credits.0.balance', 200);

        // L'agence voit son crédit et ses mouvements dans « Mon abonnement »
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.live_credit.balance', 200)
            ->assertJsonPath('data.live_credit.lines.0.amount', -500);
    }

    public function test_without_paydunya_the_fee_is_still_deducted_from_payouts(): void
    {
        config(['payments.paydunya.master_key' => null]);
        $live = $this->postJson('/api/v1/live/prepare')->assertOk()->assertJsonPath('data.offer.pay_online', false)->json('data.live.id');
        $this->postJson("/api/v1/live/{$live}/products", ['name' => 'Robe wax', 'price' => 9000, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/v1/live/{$live}/start")->assertOk()->assertJsonPath('data.live.status', 'live');

        $this->assertSame('ledger', LiveCharge::sole()->paid_via);
        $this->assertSame(-1000, (int) MerchantLedgerEntry::query()->where('merchant_id', $this->merchant->id)->sum('amount'));
        $this->assertSame(0, PlatformCredit::balance($this->company->id));
    }
}
