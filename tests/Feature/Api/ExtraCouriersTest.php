<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CourierCount;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Livreurs en plus de la formule : permis quand la formule a un prix par livreur en plus, relevés chaque jour,
 * facturés au prorata des jours où ils sont actifs.
 */
class ExtraCouriersTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Abidjan'));
        $this->buildWorld();
        // Deux livreurs dans le monde de test : la formule en permet deux
        $this->plan = Plan::create([
            'name' => 'Essentiel', 'price_monthly' => 30000, 'included_orders' => null, 'overage_per_order' => 0,
            'max_couriers' => 2, 'extra_courier_price' => 3000, 'features' => [],
        ]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    private function addCourier(string $phone): void
    {
        $this->postJson('/api/v1/users', ['name' => 'Yao', 'phone' => $phone, 'password' => 'password', 'role' => 'courier'])->assertCreated();
    }

    public function test_couriers_beyond_the_plan_are_allowed_and_counted_from_the_day_they_start(): void
    {
        Sanctum::actingAs($this->admin);
        $this->addCourier('0709090909');
        $this->addCourier('0708080808');

        $this->assertSame(4, CourierCount::query()->where('company_id', $this->company->id)->where('day', '2026-11-20')->value('couriers'));
        $data = $this->getJson('/api/v1/subscription')->assertOk()->json('data');
        $this->assertSame([3000, 2, 2], [$data['plan']['extra_courier_price'], $data['usage']['extra_couriers'], $data['usage']['extra_courier_days']]);
        // 2 jours de livreur à 3 000 F par mois de 30 jours
        $this->assertSame(200, $data['estimate']['extra_couriers']);
        $this->assertSame(30200, $data['estimate']['total']);

        // Sans prix par livreur en plus : la limite bloque comme avant
        $this->plan->update(['extra_courier_price' => null]);
        $this->postJson('/api/v1/users', ['name' => 'Ali', 'phone' => '0707070707', 'password' => 'password', 'role' => 'courier'])
            ->assertJsonValidationErrors('role');
    }

    public function test_the_invoice_bills_each_extra_courier_for_the_days_it_was_active(): void
    {
        Notification::fake();
        // Novembre : 3 livreurs pendant 10 jours (1 en plus), 5 livreurs pendant 2 jours (3 en plus), jamais en dessous
        foreach (range(1, 10) as $d) {
            CourierCount::create(['company_id' => $this->company->id, 'day' => sprintf('2026-11-%02d', $d), 'couriers' => 3]);
        }
        foreach ([11, 12] as $d) {
            CourierCount::create(['company_id' => $this->company->id, 'day' => "2026-11-{$d}", 'couriers' => 5]);
        }
        CourierCount::create(['company_id' => $this->company->id, 'day' => '2026-11-13', 'couriers' => 2]);
        CourierCount::create(['company_id' => $this->company->id, 'day' => '2026-10-31', 'couriers' => 9]);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Africa/Abidjan'));
        $invoice = app(Billing::class)->invoiceFor($this->company->fresh(), '2026-11');

        // 10 + 6 = 16 jours de livreur à 3 000 F / 30 jours = 1 600 F
        $this->assertSame([16, 3000, 1600, 30000, 31600], [$invoice->extra_courier_days, $invoice->extra_courier_price,
            $invoice->extra_couriers_amount, $invoice->base_amount, $invoice->total]);
        $this->assertSame(PlatformInvoice::OPEN, $invoice->status);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/subscription/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.extra_courier_days', 16)
            ->assertJsonPath('data.extra_couriers_amount', 1600);
    }

    public function test_weekly_invoices_bring_the_price_to_the_week(): void
    {
        Notification::fake();
        $this->company->forceFill(['billing_cycle' => PlatformInvoice::WEEKLY])->save();
        // Semaine du 9 au 15 novembre : 1 livreur en plus pendant 7 jours
        foreach (range(9, 15) as $d) {
            CourierCount::create(['company_id' => $this->company->id, 'day' => sprintf('2026-11-%02d', $d), 'couriers' => 3]);
        }

        $invoice = app(Billing::class)->invoiceFor($this->company->fresh(), '2026-W46');

        // 3 000 F par mois = 692 F par semaine (12 mois = 52 semaines)
        $this->assertSame([7, 692], [$invoice->extra_courier_days, $invoice->extra_couriers_amount]);
    }

    public function test_the_hourly_count_keeps_the_highest_number_of_the_day_and_the_console_sets_the_price(): void
    {
        $this->artisan('billing:couriers')->assertSuccessful();
        $this->assertSame(2, CourierCount::query()->where('company_id', $this->company->id)->value('couriers'));

        // Un livreur suspendu dans la journée : le plus grand nombre du jour est gardé
        User::query()->where('company_id', $this->company->id)->role(Role::Courier->value)->first()->update(['status' => 'suspended']);
        $this->artisan('billing:couriers')->assertSuccessful();
        $this->assertSame(2, CourierCount::query()->where('company_id', $this->company->id)->value('couriers'));

        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        Sanctum::actingAs($root);
        $this->patchJson("/api/v1/console/plans/{$this->plan->id}", ['extra_courier_price' => 5000])->assertOk()
            ->assertJsonPath('data.extra_courier_price', 5000);
        $this->patchJson("/api/v1/console/plans/{$this->plan->id}", ['extra_courier_price' => null])->assertOk()
            ->assertJsonPath('data.extra_courier_price', null);
    }
}
