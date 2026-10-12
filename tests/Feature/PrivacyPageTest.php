<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Politique de confidentialité : publique, sur toutes les adresses (exigée par Meta).
 */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_is_public_on_every_address(): void
    {
        config(['platform.domain' => 'jibiat.test', 'platform.operator' => 'Jibiat', 'platform.contact_email' => 'contact@jibiat.test']);
        Company::factory()->create(['slug' => 'rapide', 'name' => 'Rapide Express']);

        $this->get('http://admin.jibiat.test/confidentialite')->assertOk()
            ->assertSee('Politique de confidentialité')
            ->assertSee('id="suppression"', false)
            ->assertSee('contact@jibiat.test');

        // Adresse d'une entreprise : elle est nommée comme responsable
        $this->get('http://rapide.jibiat.test/confidentialite')->assertOk()->assertSee('Rapide Express');

        // Présentation de la plateforme : la page est servie, pas la page d'accueil
        $this->get('http://jibiat.test/confidentialite')->assertOk()->assertSee('Supprimer vos données');
    }
}
