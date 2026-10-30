<?php

namespace Tests\Feature\Api;

use App\Models\PricingGroup;
use App\Models\Zone;
use App\Models\ZoneLink;
use App\Services\Legacy\LegacyOrdersImporter;
use App\Services\Legacy\LegacyZonesImporter;
use App\Services\Zones\StandardZones;
use App\Services\Zones\ZoneLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Zones standard (liste commune du socle), prix « depuis partout » par groupes de lieux, prix cherché sur tous les
 * niveaux, rattachement et fusion des zones reprises (annulables), courses « À préciser » reclassées, tarifs proposés.
 */
class StandardZonesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        app(StandardZones::class)->sync($this->company);
        Sanctum::actingAs($this->admin);
    }

    /** Devis depuis Cocody (zone de ramassage de la boutique) */
    private function quote(array $data)
    {
        return $this->postJson('/api/v1/quotes', ['pickup_zone_id' => $this->cocody->id, ...$data]);
    }

    private function place(string $code): Zone
    {
        return Zone::forCompany($this->company->id)->where('place_code', $code)->firstOrFail();
    }

    public function test_existing_zones_are_linked_and_the_others_created_without_duplicates(): void
    {
        $this->assertSame('abidjan-cocody', $this->cocody->fresh()->place_code);
        $this->assertSame('abidjan-cocody-angre', $this->angre->fresh()->place_code);
        $this->assertSame($this->cocody->id, $this->place('abidjan-cocody-riviera-3')->parent_id);
        $this->assertFalse($this->place('bouake')->is_active);

        $count = Zone::forCompany($this->company->id)->count();
        $this->assertSame(['created' => 0, 'linked' => 0], app(StandardZones::class)->sync($this->company));
        $this->assertSame($count, Zone::forCompany($this->company->id)->count());

        // Zones et code du lieu dans l'API
        $this->getJson('/api/v1/zones')->assertOk()->assertJsonFragment(['name' => 'Siporex', 'place' => 'abidjan-yopougon-siporex']);
    }

    public function test_price_from_anywhere_by_group_and_neighborhood_override(): void
    {
        $this->postJson("/api/v1/pricing-grids/{$this->grid->id}/groups", [
            'name' => 'Nord', 'price' => 2000, 'zone_ids' => [$this->yopougon->id, $this->plateau->id],
        ])->assertCreated()->assertJsonPath('data.zones.0.name', 'Plateau');
        $siporex = $this->place('abidjan-yopougon-siporex');

        // Trajet particulier Cocody -> Yopougon (1 500 F) avant le groupe ; Plateau par le groupe
        $this->quote(['delivery_zone_id' => $siporex->id])->assertOk()->assertJsonPath('data.total', 1500);
        $this->quote(['delivery_zone_id' => $this->plateau->id])->assertOk()
            ->assertJsonPath('data.total', 2000)->assertJsonPath('data.pricing_rule_id', null);

        // Un quartier placé dans un autre groupe prend son prix ; le retirer de « Nord » n'est pas nécessaire
        $id = $this->postJson("/api/v1/pricing-grids/{$this->grid->id}/groups", [
            'name' => 'Loin', 'price' => 2500, 'zone_ids' => [$this->place('abidjan-plateau-indenie')->id, $this->plateau->id],
        ])->assertCreated()->json('data.id');
        $this->assertSame([$this->yopougon->id], PricingGroup::where('name', 'Nord')->first()->zones->pluck('id')->all());
        $this->quote(['delivery_place' => 'abidjan-plateau-indenie'])->assertOk()->assertJsonPath('data.total', 2500);

        // Vérifier un prix : d'où vient-il
        $this->getJson("/api/v1/pricing-grids/{$this->grid->id}/check?pickup_zone_id={$this->cocody->id}&delivery_zone_id={$this->plateau->id}")
            ->assertOk()->assertJsonPath('data.source', 'group')->assertJsonPath('data.group', 'Loin');
        // Lieux encore sans prix : Koumassi (commune entière)
        $missing = collect($this->getJson("/api/v1/pricing-grids/{$this->grid->id}/groups")->json('data.missing'))->pluck('name');
        $this->assertContains('Koumassi', $missing);
        $this->assertNotContains('Indénié', $missing);

        $this->deleteJson("/api/v1/pricing-grids/{$this->grid->id}/groups/{$id}")->assertNoContent();
    }

    public function test_route_prices_climb_every_level(): void
    {
        // Sous-quartier d'Angré : le trajet Cocody -> Cocody s'applique encore
        $lot = $this->zone('8e tranche', $this->angre);
        $this->quote(['delivery_zone_id' => $lot->id])->assertOk()->assertJsonPath('data.total', 1000);
    }

    public function test_public_api_accepts_a_place_code(): void
    {
        $this->quote(['delivery_place' => 'abidjan-cocody-angre'])->assertOk()->assertJsonPath('data.total', 1000);
        $this->quote(['delivery_place' => 'nulle-part'])->assertUnprocessable()->assertJsonValidationErrors('delivery_place');
        $this->place('abidjan-koumassi')->update(['is_active' => false]);
        $this->place('abidjan-koumassi-remblais')->update(['is_active' => false]);
        $this->quote(['delivery_place' => 'abidjan-koumassi-remblais'])->assertUnprocessable()
            ->assertJsonPath('errors.delivery_place.0', 'Ce lieu n\'est pas desservi : « Remblais, Koumassi ».');
    }

    public function test_legacy_zones_link_to_places_and_keep_their_price(): void
    {
        $result = app(LegacyZonesImporter::class)->importRows($this->company, [
            ['id' => 1, 'destination' => 'COCODY', 'price' => 1500],
            ['id' => 2, 'destination' => 'YOP', 'price' => 2000],
            ['id' => 3, 'destination' => 'YOP SIPOREX (2000F)', 'price' => 2000],
            ['id' => 4, 'destination' => 'Carrefour Kennedy', 'price' => 2500],
        ]);
        $this->assertSame(['zones_created' => 2, 'zones_existing' => 2], $result['counts']);
        $this->assertSame(1500, $this->cocody->fresh()->legacy_price);
        $this->assertSame(['Yop'], $this->yopougon->fresh()->aliases);
        $this->assertSame(2000, $this->yopougon->fresh()->legacy_price);

        // Rattacher : proposition sûre, à confirmer, aucune
        $proposals = collect($this->getJson('/api/v1/company/zone-links')->assertOk()->json('data.proposals'))->keyBy('name');
        $this->assertSame(['confirm', 'abidjan-yopougon-siporex'], [$proposals['Yop Siporex']['confidence'], $proposals['Yop Siporex']['place']['code']]);
        $this->assertSame('none', $proposals['Carrefour Kennedy']['confidence']);
        $this->assertNotNull($proposals['Yop Siporex']['merge_into']);
    }

    public function test_merge_moves_everything_and_can_be_undone(): void
    {
        $old = $this->zone('Riv 3');
        $old->update(['legacy_price' => 1800]);
        $riviera = $this->place('abidjan-cocody-riviera-3');
        $order = $this->createOrder();
        $order->forceFill(['delivery_zone_id' => $old->id])->save();
        $this->merchant->update(['pickup_zone_id' => $old->id]);
        $rule = $this->rule($this->grid, $this->cocody, $old, 1700);
        DB::table('courier_rules')->insert(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'type' => 'pickup',
            'zone_ids' => json_encode([$old->id, $this->cocody->id]), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $proposal = collect($this->getJson('/api/v1/company/zone-links')->json('data.proposals'))->firstWhere('name', 'Riv 3');
        $this->assertSame(['sure', $riviera->id], [$proposal['confidence'], $proposal['merge_into']['id']]);
        $this->postJson('/api/v1/company/zone-links/sure')->assertOk()->assertJsonPath('data.count', 1);

        $this->assertSame($riviera->id, $order->fresh()->delivery_zone_id);
        $this->assertSame($riviera->id, $this->merchant->fresh()->pickup_zone_id);
        $this->assertSame($riviera->id, $rule->fresh()->destination_zone_id);
        $this->assertSame([$riviera->id, $this->cocody->id], json_decode(DB::table('courier_rules')->value('zone_ids'), true));
        $this->assertSame(['Riv 3'], $riviera->fresh()->aliases);
        $this->assertSame(1800, $riviera->fresh()->legacy_price);
        $this->assertFalse($old->fresh()->is_active);
        // Le prix de l'ancien trajet suit
        $this->quote(['delivery_zone_id' => $riviera->id])->assertOk()->assertJsonPath('data.total', 1700);

        $link = ZoneLink::sole();
        $this->postJson("/api/v1/company/zone-links/{$link->id}/undo")->assertOk();
        $this->assertSame($old->id, $order->fresh()->delivery_zone_id);
        $this->assertSame($old->id, $this->merchant->fresh()->pickup_zone_id);
        $this->assertSame($old->id, $rule->fresh()->destination_zone_id);
        $this->assertTrue($old->fresh()->is_active);
        $this->assertNull($riviera->fresh()->aliases);
    }

    public function test_link_attaches_a_zone_under_its_commune_and_keep_hides_it(): void
    {
        // Lieu sans zone chez l'entreprise (retirée) : la zone reprise le prend
        $this->place('abidjan-cocody-faya')->delete();
        $old = $this->zone('FAYA');
        $this->postJson("/api/v1/company/zone-links/zones/{$old->id}", ['place' => 'abidjan-cocody-faya'])->assertOk();
        $old->refresh();
        $this->assertSame(['abidjan-cocody-faya', $this->cocody->id, 'Faya'], [$old->place_code, $old->parent_id, $old->name]);

        $own = $this->zone('Carrefour Kennedy');
        $this->postJson("/api/v1/company/zone-links/zones/{$own->id}/keep")->assertOk();
        $this->assertNotContains('Carrefour Kennedy', collect($this->getJson('/api/v1/company/zone-links')->json('data.proposals'))->pluck('name'));
        $this->assertSame(['Faya, Cocody'], collect($this->getJson('/api/v1/company/zone-links/places?q=faya')->json('data'))->pluck('label')->all());
    }

    public function test_unknown_orders_are_reclassed_from_the_old_commune(): void
    {
        $unknown = Zone::factory()->create(['company_id' => $this->company->id, 'name' => LegacyOrdersImporter::UNKNOWN_ZONE, 'is_active' => false]);
        $order = $this->createOrder();
        $order->forceFill(['legacy_id' => '501', 'delivery_zone_id' => $unknown->id])->save();
        $other = $this->createOrder();
        $other->forceFill(['legacy_id' => '502', 'delivery_zone_id' => $unknown->id])->save();

        $result = app(ZoneLinks::class)->reclass($this->company, app(LegacyOrdersImporter::class), fn (array $ids) => [
            '501' => ['id' => 501, 'commune' => 'yop siporex'],
            '502' => ['id' => 502, 'commune' => 'Nulle part'],
        ]);
        $this->assertSame(['checked' => 2, 'reclassed' => 1, 'left' => 1], $result);
        $this->assertSame($this->place('abidjan-yopougon-siporex')->id, $order->fresh()->delivery_zone_id);
        $this->assertSame($unknown->id, $other->fresh()->delivery_zone_id);
    }

    public function test_proposed_prices_become_a_group_only_when_validated(): void
    {
        $this->plateau->update(['legacy_price' => 2000]);
        $this->yopougon->update(['legacy_price' => 2000]);
        $this->zone('Carrefour Kennedy')->update(['legacy_price' => 2500]);

        $data = $this->getJson('/api/v1/company/zone-links/prices')->assertOk()->json('data');
        $this->assertSame([2000], array_column($data['groups'], 'price'));
        $this->assertSame(['Carrefour Kennedy'], array_column($data['unlinked'], 'label'));
        $this->quote(['delivery_zone_id' => $this->plateau->id])->assertUnprocessable();

        $this->postJson('/api/v1/company/zone-links/prices', ['zone_ids' => [$this->plateau->id, $this->yopougon->id], 'price' => 2200])->assertOk();
        $this->quote(['delivery_zone_id' => $this->plateau->id])->assertOk()->assertJsonPath('data.total', 2200);
        $this->assertSame([], $this->getJson('/api/v1/company/zone-links/prices')->json('data.groups'));
    }
}
