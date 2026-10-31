<?php

namespace Tests\Feature;

use App\Console\Commands\DemoAutomation;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\CourierRule;
use App\Models\Order;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Entreprise de démonstration de l'automatisation : réglée, planning en place, courses confiées toutes seules ;
 * jamais en production.
 */
class DemoAutomationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_company_assigns_its_orders_by_itself(): void
    {
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('demo:automation', ['--courses' => 5])->assertSuccessful();
        // Relancée : rien en double
        $this->artisan('demo:automation')->assertSuccessful();

        $company = Company::where('slug', DemoAutomation::SLUG)->firstOrFail();
        $this->assertSame(Company::AUTO_ON, $company->auto_pickup);
        $this->assertSame(Company::AUTO_ON, $company->auto_delivery);
        $this->assertSame(11, CourierRule::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $orders = Order::withoutGlobalScopes()->where('company_id', $company->id)->with('assignments')->get();
        $this->assertCount(5, $orders);
        foreach ($orders as $order) {
            $this->assertSame(OrderStatus::PickupAssigned, $order->status);
            $this->assertCount(1, $order->assignments);
        }
    }

    public function test_it_never_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('demo:automation')->assertFailed();
        $this->assertNull(Company::where('slug', DemoAutomation::SLUG)->first());
    }
}
