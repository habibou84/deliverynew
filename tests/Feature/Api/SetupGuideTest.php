<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Parcours de mise en route : étapes vérifiées sur les vraies données, essentielles et conseillées, « plus tard »
 * réservé aux étapes conseillées, page réservée aux administrateurs.
 */
class SetupGuideTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function step(string $key): array
    {
        return collect($this->getJson('/api/v1/setup')->assertOk()->json('data.steps'))->firstWhere('key', $key);
    }

    public function test_steps_follow_the_real_data(): void
    {
        Sanctum::actingAs($this->admin);

        // Tarifs : Cocody et Yopougon ont leurs prix (dans les deux sens), Plateau aucun
        $pricing = $this->step('pricing');
        $this->assertSame('partial', $pricing['status']);
        $this->assertStringContainsString('6 trajet(s) sans prix sur 9', $pricing['lines'][0]);
        $this->rule($this->grid, $this->plateau, $this->plateau, 800);
        $this->rule($this->grid, $this->plateau, $this->cocody, 1200);
        $this->rule($this->grid, $this->plateau, $this->yopougon, 1500);
        $this->rule($this->grid, $this->yopougon, $this->yopougon, 1000);
        $this->assertSame('done', $this->step('pricing')['status']);

        // Livreurs : créés, mais aucun ne s'est encore connecté ni n'a de zone
        $this->assertSame('partial', $this->step('couriers')['status']);
        $this->courierA->zones()->sync([$this->cocody->id]);
        $this->courierA->forceFill(['last_seen_at' => now()])->save();
        $this->assertSame('done', $this->step('couriers')['status']);

        // Une première course livrée
        $this->assertSame('todo', $this->step('first_delivery')['status']);
        $this->createOrder()->forceFill(['status' => OrderStatus::Delivered])->save();
        $this->assertSame('done', $this->step('first_delivery')['status']);

        // Sans zones : à faire
        Zone::query()->update(['is_active' => false]);
        $this->assertSame('todo', $this->step('zones')['status']);

        $data = $this->getJson('/api/v1/setup')->json('data');
        $this->assertFalse($data['ready']);
        $this->assertSame(8, $data['essentials']['total']);
    }

    public function test_only_recommended_steps_can_wait_and_admins_only(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/setup/skip', ['step' => 'messages', 'skipped' => true])->assertOk()
            ->assertJsonPath('data.steps.8.key', 'messages')->assertJsonPath('data.steps.8.skipped', true);
        $this->postJson('/api/v1/setup/skip', ['step' => 'pricing', 'skipped' => true])->assertJsonValidationErrors('step');
        $this->postJson('/api/v1/setup/skip', ['step' => 'messages', 'skipped' => false])->assertOk()->assertJsonPath('data.steps.8.skipped', false);

        // Ouverture à la première connexion : une seule fois par administrateur
        $this->getJson('/api/v1/setup')->assertJsonPath('data.seen', false);
        $this->postJson('/api/v1/setup/seen')->assertNoContent();
        $this->getJson('/api/v1/setup')->assertJsonPath('data.seen', true);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/setup')->assertForbidden();
    }
}
