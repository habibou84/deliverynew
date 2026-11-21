<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\PlaceReport;
use App\Models\PlatformSetting;
use App\Models\Zone;
use App\Services\Zones\StandardZones;
use App\Services\Zones\ZoneLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Jibiat\Socle\Places\Places;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Lieux : un seul référentiel (liste commune du socle). Les adresses de l'ancienne application sont à part (commandes
 * reprises seulement, entreprises qui ont fait la reprise) ; un quartier manquant se signale au lieu d'être créé.
 */
class LegacyAddressesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Zone $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->forceFill(['legacy_import_used_at' => now()->subMonth()])->save();
        $this->old = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'CARREFOUR SOCOCE', 'legacy' => true, 'legacy_price' => 1500]);
    }

    public function test_old_addresses_are_never_offered_as_destinations(): void
    {
        Sanctum::actingAs($this->admin);
        $names = array_column($this->getJson('/api/v1/zones')->assertOk()->json('data'), 'name');
        $this->assertContains('Cocody', $names);
        $this->assertNotContains('CARREFOUR SOCOCE', $names);

        // API publique (Jibiat Marchand) : pas non plus
        [, $key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read'], $this->merchantUser);
        $this->app['auth']->forgetGuards();
        $public = array_column($this->getJson('/api/public/v1/zones', ['Authorization' => "Bearer {$key}"])->assertOk()->json('data'), 'name');
        $this->assertNotContains('CARREFOUR SOCOCE', $public);
    }

    public function test_old_addresses_have_their_own_list_for_companies_that_took_over_the_old_app(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createOrder()->forceFill(['delivery_zone_id' => $this->old->id])->saveQuietly();

        $list = $this->getJson('/api/v1/zones/legacy')->assertOk()->json('data');
        $this->assertSame([['CARREFOUR SOCOCE', 1, 1500]], array_map(fn ($z) => [$z['name'], $z['orders'], $z['legacy_price']], $list));
        $this->assertTrue($this->getJson('/api/v1/auth/me')->json('data.company.uses_legacy'));

        // Reprise fermée sur la plateforme : la rubrique disparaît
        PlatformSetting::put('legacy_enabled', '0');
        $this->getJson('/api/v1/zones/legacy')->assertNotFound();
        $this->assertFalse($this->getJson('/api/v1/auth/me')->json('data.company.uses_legacy'));

        // Entreprise qui n'a pas fait la reprise : jamais
        PlatformSetting::put('legacy_enabled', '1');
        $this->company->forceFill(['legacy_import_used_at' => null])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/v1/zones/legacy')->assertNotFound();
    }

    public function test_an_old_address_linked_to_a_place_becomes_a_destination_again(): void
    {
        // Le lieu a sa zone standard : l'adresse y est fusionnée (ses commandes passent sur la zone du lieu)
        $order = $this->createOrder();
        $order->forceFill(['delivery_zone_id' => $this->old->id])->saveQuietly();
        app(ZoneLinks::class)->link($this->old, 'abidjan-yopougon-lokoa', $this->admin);

        $target = Zone::withoutGlobalScopes()->where('company_id', $this->company->id)->where('place_code', 'abidjan-yopougon-lokoa')->firstOrFail();
        $this->assertSame($target->id, $this->old->fresh()->merged_into_id);
        $this->assertFalse($target->legacy);
        $this->assertSame($target->id, $order->fresh()->delivery_zone_id);
    }

    public function test_places_come_from_the_common_list_and_missing_ones_are_reported(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/zones', ['name' => 'Mon quartier'])->assertStatus(422);
        $this->postJson('/api/v1/zones', ['name' => 'Expédition Bouaké (gare UTB)', 'is_shipping' => true])->assertCreated();

        $this->postJson('/api/v1/place-reports', ['name' => 'Angré Nouveau CHU', 'parent_code' => 'abidjan-cocody'])->assertCreated();
        // Déjà dans la liste : dit, pas de doublon
        $this->postJson('/api/v1/place-reports', ['name' => 'Riv 3', 'parent_code' => 'abidjan-cocody'])->assertStatus(422);
        $this->assertSame(['Angré Nouveau CHU'], PlaceReport::pluck('name')->all());
    }

    public function test_new_places_of_the_list_reach_every_company(): void
    {
        PlatformSetting::put('places_checksum', 'ancienne');
        Artisan::call('places:sync');

        $this->assertTrue(Zone::withoutGlobalScopes()->where('company_id', $this->company->id)->where('place_code', 'abidjan-yopougon-lokoa')->exists());
        $this->assertSame(Places::checksum(), PlatformSetting::get('places_checksum'));

        // Villes de l'intérieur et leurs quartiers : par Expédition (masqués) ; Grand-Bassam livré directement
        $zone = fn (string $code) => Zone::withoutGlobalScopes()->where('company_id', $this->company->id)->where('place_code', $code)->firstOrFail();
        $this->assertFalse($zone('bouake')->is_active);
        $this->assertFalse($zone('bouake-commerce')->is_active);
        $this->assertTrue($zone('grand-bassam-moossou')->is_active);
        $this->assertTrue($zone('abidjan-songon-songon-agban')->is_active);
        $this->assertContains('Bouaké', StandardZones::shippingCities());
        $this->assertNotContains('Grand-Bassam', StandardZones::shippingCities());
    }
}
