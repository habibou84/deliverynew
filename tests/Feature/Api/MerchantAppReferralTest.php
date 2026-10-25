<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\MerchantAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jibiat\Socle\Contract\Contract;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Parrainage sur Jibiat Marchand : Jibiat Marchand rapporte le compte du marchand parrainé (PUT merchant-app),
 * l'entreprise voit ses e-commerçants parrainés et sa part de leur abonnement, le marchand voit l'invitation.
 */
class MerchantAppReferralTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(array $commission = []): array
    {
        $body = Contract::example('merchant-app')['data'];
        unset($body['reported_at']);
        $body['commission'] = [...$body['commission'], ...$commission];

        return $body;
    }

    public function test_jibiat_marchand_reports_the_referred_merchant_account(): void
    {
        [, $key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read'], $this->merchantUser);
        $headers = ['Authorization' => 'Bearer '.$key, 'X-Client-App' => 'jibiat-marchand'];

        $response = $this->putJson('/api/public/v1/merchant-app', $this->report(), $headers)->assertOk();
        $this->assertSame([], Contract::validate('merchant-app', $response->json()));
        $this->assertSame('active', $response->json('data.plan.status'));

        // Rapport du lendemain : la même fiche est mise à jour
        $this->putJson('/api/public/v1/merchant-app', $this->report(['earned_total' => 4000, 'earned_month' => 0]), $headers)->assertOk();
        $account = MerchantAppAccount::sole();
        $this->assertSame($this->merchant->id, $account->merchant_id);
        $this->assertSame($this->company->id, $account->company_id);
        $this->assertSame(4000, $account->earned_total);

        $this->putJson('/api/public/v1/merchant-app', [...$this->report(), 'plan' => ['status' => 'gratuit']], $headers)->assertStatus(422);
        $this->putJson('/api/public/v1/merchant-app', $this->report())->assertUnauthorized();
    }

    public function test_the_company_sees_its_referred_merchants_and_its_share(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Fatou Beauté']);
        foreach ([[$this->merchant, 'active', 3000, 2000, 1000], [$other, 'trialing', 0, 0, 0]] as [$merchant, $status, $earned, $paid, $month]) {
            MerchantAppAccount::create([
                'company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'store_name' => $merchant->business_name,
                'store_url' => 'https://boutique.jibiat-marchand.com', 'referred' => true, 'plan_status' => $status,
                'commission_rate' => 0.2, 'commission_months' => 12, 'earned_total' => $earned, 'earned_month' => $month,
                'paid_total' => $paid, 'reported_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/jibiat-marchand/referrals')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.store_name', $this->merchant->business_name)
            ->assertJsonPath('meta.referred', 2)
            ->assertJsonPath('meta.paying', 1)
            ->assertJsonPath('meta.earned_month', 1000)
            ->assertJsonPath('meta.due', 1000)
            ->assertJsonPath('meta.rate', 0.2);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/jibiat-marchand/referrals')->assertForbidden();
    }

    public function test_the_merchant_is_invited_to_jibiat_marchand_until_he_joins(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/shop')->assertOk()
            ->assertJsonPath('data.jibiat_marchand_url', 'https://jibiat-marchand.com/creer-ma-boutique?partenaire='.$this->company->slug);

        MerchantAppAccount::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'store_name' => 'Awa Mode',
            'store_url' => 'https://awa.jibiat-marchand.com', 'reported_at' => now(),
        ]);
        $this->getJson('/api/v1/shop')->assertOk()->assertJsonPath('data.jibiat_marchand_url', null);
    }
}
