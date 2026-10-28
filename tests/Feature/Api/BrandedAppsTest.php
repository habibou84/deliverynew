<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BrandedApp;
use App\Models\BrandedAppBuild;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\BrandedApps;
use App\Services\Platform\GithubAppsToken;
use App\Services\Platform\PlatformHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Console › Applications à leur nom : applications Android au nom d'une entreprise, clé de signature gardée par
 * Jibiat, fabrication par le workflow GitHub qui lit la fiche et renvoie les fichiers avec le jeton de la version.
 */
class BrandedAppsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->forceFill(['slug' => 'livraison-express', 'name' => 'Livraison Express'])->save();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        Storage::fake('local');
    }

    public function test_company_is_added_with_its_signing_key_and_settings(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated()
            ->assertJsonPath('data.apps.ecommercant.app_id', 'ci.livraisonexpress.ecommercant')
            ->assertJsonPath('data.apps.livreur.app_id', 'ci.livraisonexpress.livreur')
            ->assertJsonPath('data.apps.ecommercant.name', 'Livraison Express')
            ->assertJsonPath('data.state', 'link')
            ->assertJsonMissingPath('data.keystore');

        // Clé PKCS12 lisible avec son mot de passe, alias « upload », certificat de 25 ans
        $app = BrandedApp::sole();
        $this->assertTrue(openssl_pkcs12_read(base64_decode($app->keystore), $certs, $app->keystore_password));
        $this->assertSame('upload', $app->key_alias);
        $this->assertGreaterThan(now()->addYears(24)->timestamp, openssl_x509_parse($certs['cert'])['validTo_time_t']);
        // Gardée chiffrée en base
        $this->assertStringNotContainsString($app->keystore_password, (string) BrandedApp::query()->toBase()->value('keystore_password'));

        $this->getJson('/api/v1/console/branded-apps')->assertOk()->assertJsonPath('data.apps.0.company.name', 'Livraison Express')->assertJsonCount(0, 'data.companies');

        // Réglages : identifiants vérifiés, liste avant la publication
        $this->putJson("/api/v1/console/branded-apps/{$this->company->id}", ['merchant_app_id' => 'Pas-Un-Identifiant'])->assertJsonValidationErrors('merchant_app_id');
        $data = $this->putJson("/api/v1/console/branded-apps/{$this->company->id}", [
            'merchant_app_name' => 'LE Commerçant',
            'short_description' => 'Vos livraisons à Abidjan',
            'full_description' => 'Créez vos courses, suivez vos colis, recevez votre argent.',
            'google_linked' => true,
        ])->assertOk()->json('data');
        $this->assertSame('LE Commerçant', $data['apps']['ecommercant']['name']);
        $checks = collect($data['checklist'])->pluck('ok', 'key');
        $this->assertTrue($checks['google'] && $checks['description'] && $checks['key'] && $checks['deletion'] && $checks['privacy']);
        $this->assertSame('todo', $data['state']);
    }

    public function test_reading_for_the_team_actions_for_the_owner_only(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated();

        Sanctum::actingAs(User::factory()->withRole(Role::PlatformSupport)->create(['company_id' => null]));
        $this->getJson("/api/v1/console/branded-apps/{$this->company->id}")->assertOk();
        $this->putJson("/api/v1/console/branded-apps/{$this->company->id}", ['short_description' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/branded-apps')->assertForbidden();
    }

    public function test_without_github_token_the_build_fails_with_an_explanation(): void
    {
        config(['services.github_apps.token' => null]);
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated();

        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated()
            ->assertJsonPath('build.status', 'failed')
            ->assertJsonPath('build.version_name', '1.0.1')
            ->assertJsonPath('data.state', 'link');
        $this->assertStringContainsString('Jeton GitHub absent', BrandedAppBuild::sole()->error);
        $this->assertNull(BrandedAppBuild::sole()->token_hash);
    }

    public function test_github_builds_the_apps_with_the_version_token(): void
    {
        config(['services.github_apps' => ['token' => 'gh-token', 'repo' => 'habibou84/deliverynew', 'ref' => 'production', 'workflow' => 'branded-app.yml', 'version' => '1.4']]);
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated();

        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated()
            ->assertJsonPath('build.status', 'queued')
            ->assertJsonPath('build.version_name', '1.4.1');
        // Une seule fabrication à la fois
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertStatus(422);

        $build = BrandedAppBuild::sole();
        $token = null;
        Http::assertSent(function (HttpRequest $r) use (&$token, $build) {
            $token = $r['inputs']['token'] ?? null;

            return $r->url() === 'https://api.github.com/repos/habibou84/deliverynew/actions/workflows/branded-app.yml/dispatches'
                && $r->hasHeader('Authorization', 'Bearer gh-token') && $r['ref'] === 'production'
                && $r['inputs']['build_id'] === (string) $build->id;
        });
        $this->assertSame(48, strlen((string) $token));

        $base = "/api/public/apps/builds/{$build->id}";
        // Sans jeton ou jeton faux : refusé
        $this->getJson("{$base}/manifest")->assertForbidden();
        $this->getJson("{$base}/manifest", ['X-Build-Token' => 'faux'])->assertForbidden();

        $manifest = $this->getJson("{$base}/manifest", ['X-Build-Token' => $token])->assertOk()->json('data');
        $this->assertSame(['ci.livraisonexpress.ecommercant', 'Livraison Express'], [$manifest['apps']['ecommercant']['app_id'], $manifest['apps']['ecommercant']['name']]);
        $this->assertSame(['livraison-express', 'Livraison Express'], [$manifest['company']['code'], $manifest['company']['name']]);
        $this->assertSame([1, '1.4.1', 'upload'], [$manifest['build']['version_code'], $manifest['build']['version_name'], $manifest['signing']['alias']]);
        $this->assertTrue(openssl_pkcs12_read(base64_decode($manifest['signing']['keystore']), $certs, $manifest['signing']['password']));

        $this->postJson("{$base}/status", ['status' => 'building', 'run_url' => 'https://github.com/habibou84/deliverynew/actions/runs/1'], ['X-Build-Token' => $token])->assertOk();
        $this->assertSame('building', $build->fresh()->status);

        $send = fn (string $app, string $kind) => $this->post("{$base}/files", [
            'app' => $app,
            'kind' => $kind,
            'file' => UploadedFile::fake()->create("app-release.{$kind}", 20),
        ], ['X-Build-Token' => $token, 'Accept' => 'application/json']);
        $send('ecommercant', 'aab')->assertOk()->assertJsonPath('data.status', 'building');
        $send('ecommercant', 'apk')->assertOk();
        $send('livreur', 'apk')->assertOk()->assertJsonPath('data.status', 'building');
        $send('livreur', 'aab')->assertOk()->assertJsonPath('data.status', 'ready');
        Storage::disk('local')->assertExists("branded-apps/{$this->company->id}/1/livreur.aab");

        // Version prête : le jeton ne sert plus
        $this->getJson("{$base}/manifest", ['X-Build-Token' => $token])->assertForbidden();

        // Console : téléchargement, identifiants figés, version publiée
        $this->get("/api/v1/console/branded-apps/{$this->company->id}/builds/{$build->id}/livreur.aab")->assertOk()
            ->assertDownload('livraison-express-livreur-1.4.1.aab');
        $data = $this->putJson("/api/v1/console/branded-apps/{$this->company->id}", ['merchant_app_id' => 'ci.autre.ecommercant'])->assertOk()->json('data');
        $this->assertSame('ci.livraisonexpress.ecommercant', $data['apps']['ecommercant']['app_id']);
        $this->assertCount(4, $data['builds'][0]['files']);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds/{$build->id}/published")->assertOk()
            ->assertJsonPath('data.builds.0.status', 'published');
    }

    public function test_an_expired_token_or_a_failed_build_is_refused(): void
    {
        config(['services.github_apps.token' => 'gh-token']);
        Http::fake(['api.github.com/*' => Http::sequence()->push(null, 204)->push(['message' => 'Bad credentials'], 401)]);
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated();
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated();
        $token = null;
        Http::assertSent(function (HttpRequest $r) use (&$token) {
            $token = $r['inputs']['token'] ?? null;

            return true;
        });
        $build = BrandedAppBuild::sole();

        $this->postJson("/api/public/apps/builds/{$build->id}/status", ['status' => 'failed', 'error' => 'Gradle : erreur'], ['X-Build-Token' => $token])->assertOk();
        $this->assertSame(['failed', 'Gradle : erreur'], [$build->fresh()->status, $build->fresh()->error]);
        $this->getJson("/api/public/apps/builds/{$build->id}/manifest", ['X-Build-Token' => $token])->assertForbidden();

        // GitHub refuse (deuxième appel) : explication
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated()
            ->assertJsonPath('build.status', 'failed')
            ->assertJsonPath('build.version_name', '1.0.2');
        $this->assertStringContainsString('Jeton GitHub refusé', BrandedAppBuild::latest('id')->first()->error);

        // Jeton expiré
        $this->travel(4)->hours();
        $this->assertFalse(app(BrandedApps::class)->authorize($build->fresh(), $token));
    }

    public function test_github_token_entered_in_the_console_is_kept_encrypted_and_used(): void
    {
        config(['services.github_apps.token' => null]);
        Http::fake([
            'api.github.com/repos/*/actions/workflows/branded-app.yml' => Http::sequence()
                ->push(['id' => 1], 200, ['github-authentication-token-expiration' => now()->addDays(20)->format('Y-m-d H:i:s').' UTC'])
                ->push(['message' => 'Bad credentials'], 401),
            'api.github.com/repos/*/dispatches' => Http::response(null, 204),
        ]);
        $token = 'github_pat_'.str_repeat('A1b2', 10).'zz9f';
        Sanctum::actingAs($this->root);

        // Pas un jeton GitHub
        $this->putJson('/api/v1/console/branded-apps/github-token', ['token' => 'n-importe-quoi'])->assertJsonValidationErrors('token');

        $this->putJson('/api/v1/console/branded-apps/github-token', ['token' => $token])->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.source', 'console')
            ->assertJsonPath('data.last4', 'zz9f')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.expires_soon', true)
            ->assertJsonMissingPath('data.token');
        // Chiffré en base, jamais renvoyé ni écrit au journal
        $this->assertStringNotContainsString($token, (string) PlatformSetting::query()->whereKey('github_apps.token')->value('value'));
        $this->assertStringNotContainsString($token, $this->getJson('/api/v1/console/branded-apps')->assertOk()->getContent());
        $this->assertStringNotContainsString($token, (string) PlatformAuditLog::query()->get()->toJson());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/actions/workflows/branded-app.yml') && $r->hasHeader('Authorization', 'Bearer '.$token));

        // Alerte de la santé de la plateforme : expire dans moins d'un mois
        $check = collect(app(PlatformHealth::class)->checks())->firstWhere('key', 'github_apps');
        $this->assertSame('warning', $check['status']);

        // La fabrication part avec ce jeton
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated();
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated()->assertJsonPath('build.status', 'queued');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/dispatches') && $r->hasHeader('Authorization', 'Bearer '.$token));

        // Vérification : jeton refusé
        $this->postJson('/api/v1/console/branded-apps/github-token/check')->assertOk()->assertJsonPath('data.error', 'Jeton refusé par GitHub (faux ou expiré).');

        // Le .env reste prioritaire et ne se modifie pas depuis la console
        config(['services.github_apps.token' => 'ghp_'.str_repeat('E', 30).'envv']);
        $this->getJson('/api/v1/console/branded-apps')->assertJsonPath('data.github.source', 'env')->assertJsonPath('data.github.last4', 'envv');
        $this->putJson('/api/v1/console/branded-apps/github-token', ['token' => $token])->assertStatus(422);
        config(['services.github_apps.token' => null]);

        // Réservé au Propriétaire
        Sanctum::actingAs(User::factory()->withRole(Role::PlatformSupport)->create(['company_id' => null]));
        $this->putJson('/api/v1/console/branded-apps/github-token', ['token' => $token])->assertForbidden();
        $this->deleteJson('/api/v1/console/branded-apps/github-token')->assertForbidden();

        // Retiré
        Sanctum::actingAs($this->root);
        $this->deleteJson('/api/v1/console/branded-apps/github-token')->assertOk()->assertJsonPath('data.ready', false);
        $this->assertNull(app(GithubAppsToken::class)->token());
    }
}
