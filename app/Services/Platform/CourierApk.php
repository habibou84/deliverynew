<?php

namespace App\Services\Platform;

use App\Support\Files;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Application Android « Jibiat Livreur » proposée au téléchargement (jibiat.com/app/livreur) : la dernière version,
 * envoyée par le workflow GitHub à chaque mise à jour, signée avec la même clé pour s'installer par-dessus.
 */
class CourierApk
{
    private const FILE = 'apps/livreur.apk';

    private const META = 'apps/livreur.json';

    /** @return array{version_code: int, version_name: string, size: int, published_at: string}|null */
    public function latest(): ?array
    {
        $disk = Files::private();
        if (! $disk->exists(self::FILE) || ! $disk->exists(self::META)) {
            return null;
        }
        $meta = json_decode((string) $disk->get(self::META), true);

        return is_array($meta) ? $meta : null;
    }

    /** Téléchargement de l'APK, lu en continu sur le disque des fichiers (local ou R2) */
    public function download(string $name): StreamedResponse
    {
        return Files::private()->download(self::FILE, $name, ['Content-Type' => 'application/vnd.android.package-archive']);
    }

    /** @return array{version_code: int, version_name: string, size: int, published_at: string} */
    public function publish(UploadedFile $file, int $versionCode, string $versionName): array
    {
        // Une APK est une archive ZIP
        if (file_get_contents($file->getRealPath(), false, null, 0, 2) !== 'PK') {
            throw new RuntimeException('Ce fichier n\'est pas une application Android.');
        }
        $current = $this->latest();
        if ($current !== null && $versionCode <= $current['version_code']) {
            throw new RuntimeException("Version {$versionCode} déjà dépassée (en ligne : {$current['version_code']}).");
        }

        $disk = Files::private();
        $disk->putFileAs('apps', $file, 'livreur.apk.part');
        $disk->move('apps/livreur.apk.part', self::FILE.'.new');
        $disk->delete(self::FILE);
        $disk->move(self::FILE.'.new', self::FILE);

        $meta = ['version_code' => $versionCode, 'version_name' => $versionName, 'size' => (int) $disk->size(self::FILE), 'published_at' => now()->toIso8601String()];
        $disk->put(self::META, json_encode($meta));

        return $meta;
    }
}
