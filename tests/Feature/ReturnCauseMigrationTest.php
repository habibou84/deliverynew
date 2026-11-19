<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Enums\Role;
use App\Models\IncidentReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis déjà en retour avant la raison du retour : la migration la retrouve dans l'historique.
 */
class ReturnCauseMigrationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    public function test_existing_returns_get_their_cause_back(): void
    {
        $this->buildWorld();
        [$refused, $merchant, $company, $exhausted, $none, $notReturned] = collect(range(1, 6))->map(fn () => $this->createOrder());

        Schema::table('orders', fn ($table) => $table->dropColumn('return_cause'));
        foreach ([$refused, $merchant, $company, $exhausted, $none] as $order) {
            DB::table('orders')->where('id', $order->id)->update(['return_requested' => true]);
        }
        DB::table('orders')->where('id', $exhausted->id)->update(['attempts_count' => 3, 'max_attempts' => 3]);
        $event = fn ($order, array $data) => DB::table('order_events')->insert(['order_id' => $order->id, 'actor_type' => 'user', 'visible_to_merchant' => true, 'created_at' => now(), ...$data]);
        $event($refused, ['type' => OrderEventType::StatusChanged->value, 'incident_reason_id' => IncidentReason::where('code', 'refused')->value('id')]);
        $event($merchant, ['type' => OrderEventType::ReturnRequested->value, 'actor_role' => Role::MerchantOwner->value]);
        $event($company, ['type' => OrderEventType::ReturnRequested->value, 'actor_role' => Role::Dispatcher->value]);

        (require database_path('migrations/2027_02_28_000101_add_return_cause_to_orders.php'))->up();

        $causes = DB::table('orders')->pluck('return_cause', 'id');
        $this->assertSame('refused', $causes[$refused->id]);
        $this->assertSame('merchant', $causes[$merchant->id]);
        $this->assertSame('company', $causes[$company->id]);
        $this->assertSame('max_attempts', $causes[$exhausted->id]);
        $this->assertNull($causes[$none->id]);
        $this->assertNull($causes[$notReturned->id]);
    }
}
