<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page de téléchargement de l'application Android des livreurs : dépôt par le workflow GitHub (jeton), page,
 * fichier APK, version en ligne pour annoncer la mise à jour.
 */
class CourierAppDownloadTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.app_upload.token' => 'jeton-depot']);
    }

    private function apk(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('app-release.apk', "PK\x03\x04".str_repeat('x', 2048));
    }

    private function upload(array $data, ?string $token = 'jeton-depot')
    {
        return $this->withHeaders(['X-Upload-Token' => (string) $token])->post('/api/public/apps/livreur', $data, ['Accept' => 'application/json']);
    }

    public function test_the_workflow_publishes_a_new_version_and_the_page_offers_it(): void
    {
        $this->get('/app/livreur')->assertOk()->assertSee('bientôt disponible');
        $this->get('/app/livreur.apk')->assertNotFound();

        $this->upload(['file' => $this->apk(), 'version_code' => 1005, 'version_name' => '1.5'])->assertCreated()
            ->assertJsonPath('data.version_code', 1005);

        $this->get('/app/livreur')->assertOk()->assertSee("Télécharger l'application", false)->assertSee('Version 1.5');
        $this->get('/app/livreur.apk')->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->getJson('/api/public/apps/livreur')->assertOk()->assertJsonPath('data.version_name', '1.5')
            ->assertJsonPath('data.download_url', url('/app/livreur.apk'));
    }

    public function test_wrong_token_old_version_or_not_an_apk_are_refused(): void
    {
        $this->upload(['file' => $this->apk(), 'version_code' => 1005, 'version_name' => '1.5'], 'mauvais')->assertForbidden();
        $this->upload(['file' => UploadedFile::fake()->createWithContent('x.apk', 'pas une archive'), 'version_code' => 1005, 'version_name' => '1.5'])->assertUnprocessable();

        $this->upload(['file' => $this->apk(), 'version_code' => 1005, 'version_name' => '1.5'])->assertCreated();
        $this->upload(['file' => $this->apk(), 'version_code' => 1004, 'version_name' => '1.4'])->assertUnprocessable();

        // Sans jeton configuré sur le serveur : aucun dépôt possible
        config(['services.app_upload.token' => null]);
        $this->upload(['file' => $this->apk(), 'version_code' => 1006, 'version_name' => '1.6'], '')->assertForbidden();
    }

    public function test_the_courier_app_reads_the_latest_version(): void
    {
        $this->buildWorld();
        $this->upload(['file' => $this->apk(), 'version_code' => 1007, 'version_name' => '1.7'])->assertCreated();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/app')->assertOk()->assertJsonPath('data.version_code', 1007);
    }
}
