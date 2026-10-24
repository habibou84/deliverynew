<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Notifications\VehicleDocumentAlert;
use App\Services\Vehicles\VehicleFleet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Parc des véhicules : fiches, affectation aux livreurs, papiers avec leur date de fin, rappels et
 * avertissement au dispatch.
 */
class VehicleTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    private function moto(array $data = []): array
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/vehicles', ['type' => 'moto', 'plate' => '1234 ab 01', 'brand' => 'Haojue', 'owner' => 'company', ...$data])
            ->assertCreated()->json('data');
    }

    public function test_a_vehicle_is_created_and_given_to_a_courier_with_its_history(): void
    {
        $vehicle = $this->moto(['courier_id' => $this->courierA->id]);
        $this->assertSame('1234 AB 01', $vehicle['plate']);
        $this->assertSame($this->courierA->id, $vehicle['courier']['id']);
        // La plaque du livreur suit (choix des missions, plans de paie)
        $this->assertSame('1234 AB 01', $this->courierA->fresh()->vehicle_plate);

        // Même plaque : refusée
        $this->postJson('/api/v1/vehicles', ['type' => 'moto', 'plate' => '1234 AB 01'])->assertStatus(422);

        // Confiée à un autre livreur, puis reprise
        $this->postJson("/api/v1/vehicles/{$vehicle['id']}/assign", ['courier_id' => $this->courierB->id])->assertOk()
            ->assertJsonPath('data.courier.id', $this->courierB->id);
        $this->assertNull($this->courierA->fresh()->vehicle_plate);
        $this->assertSame('1234 AB 01', $this->courierB->fresh()->vehicle_plate);
        $this->postJson("/api/v1/vehicles/{$vehicle['id']}/assign", ['courier_id' => null])->assertOk()->assertJsonPath('data.courier', null);

        $history = $this->getJson("/api/v1/vehicles/{$vehicle['id']}")->assertOk()->json('data.history');
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]['ended_at']);
        $this->assertSame(0, VehicleAssignment::whereNull('ended_at')->count());
    }

    public function test_a_courier_has_one_vehicle_at_a_time(): void
    {
        $first = $this->moto(['courier_id' => $this->courierA->id]);
        $second = $this->moto(['plate' => '5678 CD 01', 'courier_id' => $this->courierA->id]);

        $this->assertNull(Vehicle::find($first['id'])->courier_id);
        $this->assertSame($this->courierA->id, Vehicle::find($second['id'])->courier_id);
        $this->assertSame('5678 CD 01', $this->courierA->fresh()->vehicle_plate);

        // Retiré du parc : plus confié à personne
        $this->patchJson("/api/v1/vehicles/{$second['id']}", ['type' => 'moto', 'plate' => '5678 CD 01', 'status' => 'retired'])->assertOk()
            ->assertJsonPath('data.courier', null);
        $this->postJson("/api/v1/vehicles/{$second['id']}/assign", ['courier_id' => $this->courierB->id])->assertStatus(422);
    }

    public function test_documents_with_their_end_date_and_the_dispatch_warning(): void
    {
        $vehicle = $this->moto(['courier_id' => $this->courierA->id]);
        $this->travelTo(now()->setDate(2026, 11, 1));

        // Sans assurance : à renseigner (orange)
        $warnings = collect($this->getJson('/api/v1/couriers')->json('data'))->firstWhere('id', $this->courierA->id)['vehicle_warnings'];
        $this->assertSame([['level' => 'warn', 'text' => 'Assurance à renseigner']], $warnings);

        // Assurance expirée : rouge, avec le papier en photo
        $document = $this->post('/api/v1/vehicle-documents', [
            'vehicle_id' => $vehicle['id'], 'type' => 'insurance', 'number' => 'POL-77', 'expires_at' => '2026-10-20',
            'file' => UploadedFile::fake()->image('assurance.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.state', 'expired')->json('data');
        $this->get($document['file_url'] === null ? '/' : '/api/v1'.$document['file_url'])->assertOk();
        $courier = collect($this->getJson('/api/v1/couriers')->json('data'))->firstWhere('id', $this->courierA->id);
        $this->assertSame('Assurance expirée', $courier['vehicle_warnings'][0]['text']);
        $this->assertSame('alert', $courier['vehicle_warnings'][0]['level']);

        // Renouvelée : la plus récente compte
        $this->postJson('/api/v1/vehicle-documents', ['vehicle_id' => $vehicle['id'], 'type' => 'insurance', 'expires_at' => '2027-10-20'])->assertCreated()
            ->assertJsonPath('data.state', 'valid');
        $this->assertSame([], collect($this->getJson('/api/v1/couriers')->json('data'))->firstWhere('id', $this->courierA->id)['vehicle_warnings']);
        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonPath('data.0.insurance_state', 'valid');

        // Permis du livreur expiré
        $this->postJson('/api/v1/vehicle-documents', ['courier_id' => $this->courierA->id, 'type' => 'license', 'expires_at' => '2026-10-01'])->assertCreated();
        $this->assertSame('Permis expiré', collect($this->getJson('/api/v1/couriers')->json('data'))->firstWhere('id', $this->courierA->id)['vehicle_warnings'][0]['text']);

        // Un papier du livreur ne va pas sur un véhicule
        $this->postJson('/api/v1/vehicle-documents', ['vehicle_id' => $vehicle['id'], 'type' => 'license'])->assertStatus(422);
    }

    public function test_documents_are_reminded_15_days_before_then_on_the_last_day(): void
    {
        Notification::fake();
        $vehicle = $this->moto();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $document = VehicleDocument::create(['company_id' => $this->company->id, 'vehicle_id' => $vehicle['id'], 'type' => 'inspection', 'expires_at' => '2026-11-14']);
        $fleet = app(VehicleFleet::class);

        // 13 jours avant : premier rappel, une seule fois
        $this->assertSame(1, $fleet->remind());
        $this->assertSame(0, $fleet->remind());
        Notification::assertSentTo([$this->admin, $this->dispatcher], VehicleDocumentAlert::class, fn ($n) => $n->kind === 'soon');

        // Le jour de la fin
        $this->travelTo(now()->setDate(2026, 11, 14));
        $this->assertSame(1, $fleet->remind());
        Notification::assertSentTo($this->admin, VehicleDocumentAlert::class, fn ($n) => $n->kind === 'expired');
        $this->assertSame(0, $fleet->remind());

        // Déjà renouvelé : pas de rappel
        $this->travelTo(now()->setDate(2026, 12, 1));
        VehicleDocument::create(['company_id' => $this->company->id, 'vehicle_id' => $vehicle['id'], 'type' => 'tax', 'expires_at' => '2026-12-05']);
        VehicleDocument::create(['company_id' => $this->company->id, 'vehicle_id' => $vehicle['id'], 'type' => 'tax', 'expires_at' => '2027-12-05']);
        $this->assertSame(0, $fleet->remind());
        $this->assertNotNull($document->fresh()->expired_warned_at);
    }

    public function test_vehicles_stay_within_their_company_and_need_the_right_permission(): void
    {
        $vehicle = $this->moto();
        $other = Company::factory()->create();
        Sanctum::actingAs($this->userWithRole(Role::Admin, [], $other));
        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/vehicles/{$vehicle['id']}")->assertNotFound();

        // Le dispatch consulte, sans modifier
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/vehicles', ['type' => 'moto'])->assertForbidden();
    }
}
