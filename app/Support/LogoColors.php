<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Couleurs du logo de l'entreprise et palettes proposées pour l'espace e-commerçant. Le fond transparent, le
 * blanc, le noir et les gris sont ignorés ; chaque palette est assombrie au besoin pour que le texte blanc reste
 * lisible (contraste 4,5:1, comme le demandent les règles d'accessibilité).
 */
class LogoColors
{
    /** Contraste minimal du texte blanc sur la couleur */
    public const MIN_CONTRAST = 4.5;

    /** Part minimale d'une couleur parmi les points colorés du logo */
    private const MIN_SHARE = 0.06;

    /**
     * Couleurs repérées et palettes proposées (vides pour un logo noir et blanc, ou sans logo).
     *
     * @return array{colors: list<array<string, mixed>>, palettes: list<array<string, mixed>>}
     */
    public static function for(Company $company): array
    {
        $disk = Storage::disk('local');
        $path = $company->logo_path;
        $colors = $path !== null && $disk->exists($path) && function_exists('imagecreatefromstring')
            ? self::extract((string) $disk->get($path))
            : [];

        return ['colors' => $colors, 'palettes' => self::palettes($colors)];
    }

    /**
     * Jusqu'à trois couleurs du logo, de la plus présente à la moins présente.
     *
     * @return list<array{hex: string, name: string, share: int}>
     */
    public static function extract(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return [];
        }

        // Image réduite : assez de points pour les proportions, rapide même pour un grand logo
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, 96 / max($w, $h));
        $sw = max(1, (int) round($w * $scale));
        $sh = max(1, (int) round($h * $scale));
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagedestroy($image);

        // Points regroupés par teinte (12 familles) et par clarté (foncé, moyen, clair)
        $groups = [];
        $total = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $c = imagecolorat($small, $x, $y);
                if ((($c >> 24) & 0x7F) > 64) {
                    continue; // transparent
                }
                $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                [$hue, $sat, $light] = self::toHsl($rgb);
                if ($light > 0.92 || $light < 0.1 || $sat < 0.2) {
                    continue; // blanc, noir, gris
                }
                $key = intdiv((int) round($hue), 30) % 12 .'-'.min(2, (int) floor($light * 3));
                $groups[$key] ??= ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                $groups[$key]['n']++;
                $groups[$key]['r'] += $rgb[0];
                $groups[$key]['g'] += $rgb[1];
                $groups[$key]['b'] += $rgb[2];
                $total++;
            }
        }
        imagedestroy($small);

        if ($total < 20) {
            return [];
        }

        usort($groups, fn ($a, $b) => $b['n'] <=> $a['n']);
        $found = [];
        foreach ($groups as $group) {
            if ($group['n'] / $total < self::MIN_SHARE) {
                break;
            }
            $rgb = [(int) round($group['r'] / $group['n']), (int) round($group['g'] / $group['n']), (int) round($group['b'] / $group['n'])];
            // Deux groupes voisins presque identiques : une seule couleur
            foreach ($found as $i => $kept) {
                if (self::distance($kept['rgb'], $rgb) < 60) {
                    $found[$i]['n'] += $group['n'];

                    continue 2;
                }
            }
            $found[] = ['rgb' => $rgb, 'n' => $group['n']];
        }

        usort($found, fn ($a, $b) => $b['n'] <=> $a['n']);
        $top = array_slice($found, 0, 3);
        $sum = array_sum(array_column($top, 'n'));

        return array_map(fn ($f) => [
            'hex' => self::hex($f['rgb']),
            'name' => self::name($f['rgb']),
            'share' => (int) round(100 * $f['n'] / $sum),
        ], $top);
    }

    /**
     * Palettes : couleur principale (la plus présente), couleur d'accent (la deuxième), douce (principale adoucie).
     *
     * @param  list<array{hex: string, name: string, share: int}>  $colors
     * @return list<array<string, mixed>>
     */
    public static function palettes(array $colors): array
    {
        if ($colors === []) {
            return [];
        }

        $main = self::fromHex($colors[0]['hex']);
        $palettes = [self::palette('main', 'Couleur principale', $main, $colors[0]['name'])];

        // Accent : deuxième couleur du logo, si elle est vraiment différente de la principale
        foreach (array_slice($colors, 1) as $color) {
            $rgb = self::fromHex($color['hex']);
            if (self::hueGap(self::toHsl($main)[0], self::toHsl($rgb)[0]) >= 30) {
                $palettes[] = self::palette('accent', 'Couleur d\'accent', $rgb, $color['name']);
                break;
            }
        }

        // Douce : même teinte que la principale, moins saturée
        [$hue, $sat] = self::toHsl($main);
        $soft = self::fromHsl($hue, min($sat, 0.55), 0.45);
        if (self::distance(self::fromHex($palettes[0]['color']), self::readable($soft)) >= 40) {
            $palettes[] = self::palette('soft', 'Douce', $soft, null);
        }

        return $palettes;
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    private static function palette(string $key, string $label, array $rgb, ?string $from): array
    {
        $color = self::readable($rgb);
        [$hue, $sat, $light] = self::toHsl($color);

        return [
            'key' => $key,
            'label' => $label,
            'from' => $from,
            'color' => self::hex($color),
            'dark' => self::hex(self::fromHsl($hue, $sat, $light * 0.6)),
            'light' => self::hex(self::fromHsl($hue, min($sat, 0.7), 0.94)),
            // Couleur du logo assombrie pour que le texte blanc reste lisible
            'adjusted' => self::hex($color) !== self::hex($rgb),
        ];
    }

    /**
     * La couleur, assombrie juste assez pour que le texte blanc soit lisible.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: int, 1: int, 2: int}
     */
    public static function readable(array $rgb): array
    {
        [$hue, $sat, $light] = self::toHsl($rgb);
        while (self::contrastWithWhite($rgb) < self::MIN_CONTRAST && $light > 0.05) {
            $light -= 0.02;
            // Orange et jaune assombris tirent vers le marron : on glisse un peu vers le rouge
            if ($hue > 18 && $hue < 70) {
                $hue = max(18, $hue - 1.5);
            }
            $rgb = self::fromHsl($hue, $sat, $light);
        }

        return $rgb;
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    public static function contrastWithWhite(array $rgb): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 1.05 / (0.2126 * $r + 0.7152 * $g + 0.0722 * $b + 0.05);
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    private static function name(array $rgb): string
    {
        [$hue, , $light] = self::toHsl($rgb);
        $base = match (true) {
            $hue < 15 || $hue >= 345 => 'Rouge',
            $hue < 40 => 'Orange',
            $hue < 65 => 'Jaune',
            $hue < 165 => 'Vert',
            $hue < 195 => 'Turquoise',
            $hue < 255 => 'Bleu',
            $hue < 290 => 'Violet',
            default => 'Rose',
        };
        if ($base === 'Bleu' && $light < 0.35) {
            return 'Bleu nuit';
        }

        return $base.match (true) {
            $light < 0.3 => ' foncé',
            $light > 0.65 => ' clair',
            default => '',
        };
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: float, 1: float, 2: float} teinte (0-360), saturation et clarté (0-1)
     */
    private static function toHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn ($c) => $c / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $light = ($max + $min) / 2;
        $delta = $max - $min;
        if ($delta == 0) {
            return [0.0, 0.0, $light];
        }
        $sat = $delta / (1 - abs(2 * $light - 1));
        $hue = match ($max) {
            $r => 60 * fmod(($g - $b) / $delta, 6),
            $g => 60 * (($b - $r) / $delta + 2),
            default => 60 * (($r - $g) / $delta + 4),
        };

        return [$hue < 0 ? $hue + 360 : $hue, min(1.0, $sat), $light];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function fromHsl(float $hue, float $sat, float $light): array
    {
        $c = (1 - abs(2 * $light - 1)) * $sat;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $light - $c / 2;
        [$r, $g, $b] = match (intdiv((int) $hue, 60) % 6) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return array_map(fn ($v) => (int) max(0, min(255, round(($v + $m) * 255))), [$r, $g, $b]);
    }

    private static function hueGap(float $a, float $b): float
    {
        $gap = abs($a - $b);

        return min($gap, 360 - $gap);
    }

    /** @param  array{0: int, 1: int, 2: int}  $a */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function fromHex(string $hex): array
    {
        return sscanf($hex, '#%02x%02x%02x');
    }
}
