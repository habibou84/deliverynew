<?php

namespace Tests\Unit;

use App\Support\ImageVariants;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Images piégées : une image annoncée comme immense n'est jamais décodée (mémoire du serveur protégée).
 */
class ImageVariantsTest extends TestCase
{
    private function resize(string $bytes): ?string
    {
        return (new ReflectionMethod(ImageVariants::class, 'resize'))->invoke(null, $bytes, 320, 'jpg');
    }

    /** PNG minimal dont l'en-tête annonce la taille voulue (contenu volontairement absent) */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    public function test_a_huge_image_is_not_decoded(): void
    {
        $this->assertNull($this->resize($this->pngHeader(20000, 20000)));
    }

    public function test_a_normal_photo_is_reduced(): void
    {
        $image = imagecreatetruecolor(1200, 800);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();

        $small = $this->resize($bytes);
        $this->assertNotNull($small);
        $this->assertSame(320, getimagesizefromstring($small)[0]);
    }
}
