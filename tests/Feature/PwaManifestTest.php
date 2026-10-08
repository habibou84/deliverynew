<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    public function test_each_mobile_app_has_its_own_manifest(): void
    {
        $this->get('/manifest/livreur.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('id', '/livreur')
            ->assertJsonPath('start_url', '/livreur?source=pwa')
            ->assertJsonPath('display', 'standalone');

        $this->get('/manifest/marchand.webmanifest')
            ->assertOk()
            ->assertJsonPath('id', '/marchand');
    }

    public function test_back_office_has_no_manifest(): void
    {
        // Pas de route : la page de l'application (HTML) est servie à la place
        $response = $this->get('/manifest/admin.webmanifest');
        $this->assertStringNotContainsString('manifest+json', (string) $response->headers->get('Content-Type'));
    }

    public function test_pages_link_the_right_manifest(): void
    {
        $this->get('/livreur')->assertOk()->assertSee('/manifest/livreur.webmanifest', false);
        $this->get('/marchand')->assertOk()->assertSee('/manifest/marchand.webmanifest', false);
        $this->get('/admin')->assertOk()->assertDontSee('webmanifest', false);
    }
}
