<?php

namespace Tests\Feature\Api;

use App\Support\Appearance;
use App\Support\LogoColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Paramètres › Apparence : couleurs des espaces, noms et icônes des applications installables.
 */
class AppearanceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // Pages de l'application rendues sans l'interface compilée (absente en CI)
        $this->withoutVite();
        Storage::fake('local');
        config(['app.branding_company' => $this->company->id]);
    }

    private function spaces(array $override = []): array
    {
        return array_replace_recursive([
            'marchand' => ['color' => '#7C3AED', 'app_name' => 'Express Pro'],
            'livreur' => ['color' => '#be123c', 'app_name' => 'Express Livreur'],
            'admin' => ['color' => '#0e7490'],
        ], $override);
    }

    public function test_each_space_gets_its_own_colors_and_app_names(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/appearance')->assertOk()
            ->assertJsonPath('data.spaces.livreur.color', '#1d4ed8');

        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.marchand.color', '#7c3aed')
            ->assertJsonPath('data.spaces.admin.color', '#0e7490');

        // Pages publiques et manifestes des applications
        $this->getJson('/api/v1/branding')->assertJsonPath('data.appearance.spaces.livreur.app_name', 'Express Livreur');
        $this->get('/manifest/livreur.webmanifest')->assertJsonPath('theme_color', '#be123c')
            ->assertJsonPath('short_name', 'Express Livreur');
        $this->get('/livreur/connexion')->assertSee('<meta name="theme-color" content="#be123c">', false);

        // Même apparence : la couleur de l'espace e-commerçant partout, les noms restent propres à chaque application
        $this->putJson('/api/v1/company/appearance', ['same' => true, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.livreur.color', '#7c3aed')
            ->assertJsonPath('data.spaces.admin.color', '#7c3aed')
            ->assertJsonPath('data.spaces.livreur.app_name', 'Express Livreur');

        // Couleur invalide, ou personne d'autre que l'administrateur
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces(['livreur' => ['color' => 'rouge']])])
            ->assertUnprocessable();
        Sanctum::actingAs($this->courierA->user);
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertForbidden();
    }

    public function test_merchant_app_is_named_after_the_company_by_default(): void
    {
        $this->get('/manifest/marchand.webmanifest')
            ->assertJsonPath('name', $this->company->name)
            ->assertJsonPath('short_name', $this->company->name);
        $this->get('/manifest/livreur.webmanifest')->assertJsonPath('short_name', 'Livreur');

        // Ancien nom par défaut enregistré tel quel : le nom de l'entreprise reprend sa place
        $this->company->update(['appearance' => ['spaces' => ['marchand' => ['app_name' => 'Mes livraisons']]]]);
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('short_name', $this->company->name);

        // Nom choisi dans Paramètres › Apparence : il l'emporte
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.marchand.app_name', 'Express Pro')
            ->assertJsonPath('data.spaces.marchand.default_app_name', $this->company->name);
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('short_name', 'Express Pro');
    }

    public function test_app_icons_come_from_the_logo_or_an_uploaded_image(): void
    {
        // Sans logo ni image : icônes standard
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('icons.0.src', '/app-icons/marchand-192.png');

        Sanctum::actingAs($this->admin);
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)])->assertOk();

        // Logo : icône générée sur la couleur de l'espace
        $src = $this->get('/manifest/marchand.webmanifest')->json('icons.0.src');
        $this->assertStringStartsWith('/branding/icon/marchand/192.png?v=', $src);
        $png = $this->get($src)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([192, 192], array_slice(getimagesizefromstring($png->streamedContent() ?: file_get_contents($png->baseResponse->getFile()->getPathname())), 0, 2));

        // Image carrée envoyée pour l'application livreur, puis retirée
        $this->post('/api/v1/company/appearance/icons/livreur', ['icon' => UploadedFile::fake()->image('icone.png', 512, 512)])
            ->assertOk()->assertJsonPath('data.spaces.livreur.custom_icon', true);
        $this->get('/branding/icon/livreur/maskable-512.png')->assertOk();
        $this->delete('/api/v1/company/appearance/icons/livreur')->assertOk()->assertJsonPath('data.spaces.livreur.custom_icon', false);

        // Image trop petite refusée
        $this->post('/api/v1/company/appearance/icons/livreur', ['icon' => UploadedFile::fake()->image('petite.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    /** Logo en PNG : bandes de couleurs (largeur en points) sur fond transparent */
    private function logo(array $bands): UploadedFile
    {
        $image = imagecreatetruecolor(300, 120);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $x = 0;
        foreach ($bands as $hex => $width) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            imagefilledrectangle($image, $x, 20, $x + $width - 1, 100, imagecolorallocate($image, $r, $g, $b));
            $x += $width;
        }
        $file = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($image, $file);

        return new UploadedFile($file, 'logo.png', 'image/png', null, true);
    }

    public function test_logo_colors_are_proposed_and_applied_when_no_color_was_chosen(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post('/api/v1/company/logo', ['logo' => $this->logo(['#1e3a8a' => 180, '#f59e0b' => 90, '#60a5fa' => 30])], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.colors.0.name', 'Bleu nuit')
            ->assertJsonPath('data.colors.1.name', 'Orange')
            ->assertJsonPath('data.palettes.0.key', 'main')
            ->assertJsonPath('data.palettes.1.key', 'accent')
            ->assertJsonPath('data.palettes.1.adjusted', true)
            ->assertJsonPath('data.applied', 'main')
            ->assertJsonPath('data.appearance.spaces.marchand.color', '#1e3a8a')
            ->assertJsonPath('data.appearance.spaces.marchand.from_logo', true);

        // Texte blanc toujours lisible sur chaque palette
        foreach ($response->json('data.palettes') as $palette) {
            $this->assertGreaterThanOrEqual(LogoColors::MIN_CONTRAST, LogoColors::contrastWithWhite(sscanf($palette['color'], '#%02x%02x%02x')));
        }

        // Nouveau logo : la couleur venue de l'ancien logo est remplacée
        $green = $this->post('/api/v1/company/logo', ['logo' => $this->logo(['#16a34a' => 300])], ['Accept' => 'application/json'])
            ->assertJsonPath('data.applied', 'main')
            ->assertJsonPath('data.palettes.0.from', 'Vert')
            ->json('data.palettes.0.color');
        $this->assertSame($green, Appearance::spaceColor($this->company->fresh(), 'marchand'));

        // Annuler : retour à la couleur par défaut
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => ['marchand' => ['color' => null]]])->assertOk()
            ->assertJsonPath('data.spaces.marchand.color', '#047857')
            ->assertJsonPath('data.spaces.marchand.chosen', false);

        // Couleur choisie par l'entreprise : jamais écrasée, les palettes sont seulement proposées
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertOk();
        $this->post('/api/v1/company/logo', ['logo' => $this->logo(['#be123c' => 300])], ['Accept' => 'application/json'])
            ->assertJsonPath('data.applied', null)
            ->assertJsonPath('data.palettes.0.from', 'Rouge')
            ->assertJsonPath('data.appearance.spaces.marchand.color', '#7c3aed');
        $this->getJson('/api/v1/company/appearance/logo-colors')->assertOk()->assertJsonPath('data.colors.0.name', 'Rouge');

        // Logo noir et blanc : aucune couleur proposée
        $this->post('/api/v1/company/logo', ['logo' => $this->logo(['#000000' => 150, '#ffffff' => 150])], ['Accept' => 'application/json'])
            ->assertJsonPath('data.colors', [])
            ->assertJsonPath('data.palettes', [])
            ->assertJsonPath('data.applied', null);

        // Réservé à l'administrateur
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/company/appearance/logo-colors')->assertForbidden();
    }
}
