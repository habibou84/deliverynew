<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Étiquettes imprimées en lot : seulement les courses que la personne a le droit de voir, dans l'ordre demandé.
 */
class OrderLabelsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_labels_come_back_in_the_requested_order_and_only_when_visible(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder(['recipient_name' => 'Awa']);
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Angré']);
        $foreign = $this->createOrder([], $this->admin, $other);

        // L'équipe voit toutes les courses de l'entreprise
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/orders/labels?'.http_build_query(['ids' => [$second->id, $first->id, $foreign->id]]))
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.tracking_code', $second->tracking_code)
            ->assertJsonStructure(['data' => [['recipient', 'delivery' => ['zone_name', 'scheduled_date'], 'amounts' => ['cod_amount'], 'merchant']]]);

        // L'e-commerçant ne reçoit que les siennes : la course d'un autre est ignorée
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/orders/labels?'.http_build_query(['ids' => [$first->id, $foreign->id]]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }

    public function test_labels_need_between_one_and_one_hundred_courses(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/orders/labels')->assertJsonValidationErrors('ids');
        $this->getJson('/api/v1/orders/labels?'.http_build_query(['ids' => range(1, 101)]))->assertJsonValidationErrors('ids');

        // Un livreur n'imprime pas d'étiquettes
        Sanctum::actingAs($this->userWithRole(Role::Courier));
        $this->getJson('/api/v1/orders/labels?'.http_build_query(['ids' => [$this->createOrder()->id]]))->assertForbidden();
    }
}
