<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Images allégées : une photo de plusieurs Mo prise au téléphone devient une image de quelques dizaines de Ko, à la
 * taille demandée. WebP pour les navigateurs qui l'acceptent, sinon JPEG (photos) ou PNG (logos transparents) :
 * WhatsApp (Meta) ne prend pas le WebP pour les photos envoyées. La version réduite est créée une fois, puis gardée.
 */
class ImageVariants
{
    /** Largeurs permises (pas de taille au hasard : chaque taille est un fichier gardé) */
    public const WIDTHS = [160, 320, 640, 1080];

    /**
     * Au-delà (40 mégapixels, plus que n'importe quel téléphone), l'image n'est pas décodée : une petite image piégée de
     * 20 000 × 20 000 pixels prendrait des centaines de Mo de mémoire à chaque affichage (déni de service).
     */
    public const MAX_PIXELS = 40_000_000;

    /** Réponse avec la version réduite ; l'original si l'image ne peut pas être lue */
    public static function response(string $path, int $width, bool $keepAlpha = false, array $headers = []): Response
    {
        $disk = Storage::disk('local');
        $format = str_contains((string) request()->header('Accept'), 'image/webp') ? 'webp' : ($keepAlpha ? 'png' : 'jpg');
        $variant = self::variantPath($path, $width, $format);

        if (! $disk->exists($variant) || $disk->lastModified($variant) < $disk->lastModified($path)) {
            $data = self::resize($disk->get($path), $width, $format);
            if ($data === null) {
                return $disk->response($path, null, $headers);
            }
            $disk->put($variant, $data);
        }

        return $disk->response($variant, null, $headers + [
            'Content-Type' => ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$format],
            'Vary' => 'Accept',
        ]);
    }

    /** Supprime les versions réduites d'une image remplacée ou effacée */
    public static function forget(string $path): void
    {
        $disk = Storage::disk('local');
        $disk->delete(collect($disk->files('variants'))->filter(fn ($f) => str_starts_with(basename($f), self::key($path).'-'))->all());
    }

    /** Largeur demandée ramenée à la plus proche permise (au-dessus) */
    public static function width(mixed $requested, int $default): int
    {
        $w = (int) $requested;
        if ($w <= 0) {
            return $default;
        }

        return collect(self::WIDTHS)->first(fn ($allowed) => $allowed >= $w) ?? max(self::WIDTHS);
    }

    private static function variantPath(string $path, int $width, string $format): string
    {
        return 'variants/'.self::key($path)."-{$width}.{$format}";
    }

    private static function key(string $path): string
    {
        return substr(md5($path), 0, 16);
    }

    private static function resize(string $bytes, int $width, string $format): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        // Dimensions lues dans l'en-tête du fichier, sans décoder l'image
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        try {
            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                return null;
            }
            [$w, $h] = [imagesx($source), imagesy($source)];
            $ratio = min(1, $width / max(1, $w));
            [$nw, $nh] = [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];

            $target = imagecreatetruecolor($nw, $nh);
            if ($format === 'jpg') {
                // JPEG sans transparence : fond blanc
                imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            } else {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            }
            imagecopyresampled($target, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            match ($format) {
                'webp' => imagewebp($target, null, 80),
                'png' => imagepng($target, null, 9),
                default => imagejpeg($target, null, 82),
            };

            return ob_get_clean() ?: null;
        } finally {
            ini_set('memory_limit', $limit);
        }
    }
}
