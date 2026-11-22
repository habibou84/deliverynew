<?php

namespace Tests\Feature\Api;

use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Fichiers sur un stockage objet (Cloudflare R2, docs/FICHIERS-R2.md) : disque choisi par PRIVATE_DISK, versions
 * réduites gardées en local, copie des fichiers existants avant la bascule.
 */
class FilesOnObjectStorageTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
        Storage::fake('r2-private');
        config(['filesystems.private_disk' => 'r2-private']);
    }

    public function test_logo_is_stored_on_the_files_disk_and_reduced_locally(): void
    {
        Sanctum::actingAs($this->admin);
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])->assertOk();

        $path = $this->company->fresh()->logo_path;
        Storage::disk('r2-private')->assertExists($path);
        Storage::disk('local')->assertMissing($path);

        $png = $this->get('/branding/logo', ['Accept' => '*/*'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([300, 120], array_slice(getimagesizefromstring($png->streamedContent()), 0, 2));
        // Version réduite : calculée une fois, gardée sur le disque du serveur
        $this->assertNotEmpty(Storage::disk('local')->files('variants'));
        $this->assertEmpty(Storage::disk('r2-private')->files('variants'));
    }

    public function test_courier_app_is_downloaded_from_the_files_disk(): void
    {
        config(['services.app_upload.token' => 'jeton-depot']);
        $apk = UploadedFile::fake()->createWithContent('app-release.apk', "PK\x03\x04".str_repeat('x', 2048));
        $this->withHeaders(['X-Upload-Token' => 'jeton-depot'])
            ->post('/api/public/apps/livreur', ['file' => $apk, 'version_code' => 1005, 'version_name' => '1.5'], ['Accept' => 'application/json'])
            ->assertCreated();

        Storage::disk('r2-private')->assertExists('apps/livreur.apk');
        $response = $this->get('/app/livreur.apk')->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->assertStringStartsWith("PK\x03\x04", $response->streamedContent());
    }

    public function test_copy_keeps_paths_and_moves_course_attachments(): void
    {
        $local = Storage::disk('local');
        $local->put('orders/1/photo.jpg', 'JPEG');
        $local->put('variants/abc-320.webp', 'cache');
        $order = $this->createOrder();
        $id = DB::table('order_attachments')->insertGetId(['order_id' => $order->id, 'type' => 'photo_delivery', 'disk' => 'local', 'path' => 'orders/1/photo.jpg']);

        $this->artisan('files:copy', ['from' => 'local', 'to' => 'r2-private', '--except' => ['variants']])
            ->expectsOutputToContain('1 copié(s)')->assertSuccessful();

        $this->assertSame('JPEG', Files::private()->get('orders/1/photo.jpg'));
        Storage::disk('r2-private')->assertMissing('variants/abc-320.webp');
        $this->assertSame('r2-private', DB::table('order_attachments')->where('id', $id)->value('disk'));
    }
}
