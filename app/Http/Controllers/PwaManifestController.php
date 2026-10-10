<?php

namespace App\Http\Controllers;

use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;

/**
 * Manifestes des deux applications installables (PWA) : l'espace e-commerçant
 * et l'application livreur s'installent comme deux applications distinctes.
 */
class PwaManifestController extends Controller
{
    private const APPS = [
        'marchand' => [
            'short_name' => 'Mes livraisons',
            'suffix' => 'E-commerçant',
            'description' => 'Créez et suivez vos livraisons, recevez vos paiements.',
            'theme_color' => '#047857',
        ],
        'livreur' => [
            'short_name' => 'Livreur',
            'suffix' => 'Livreur',
            'description' => 'Vos ramassages et livraisons du jour.',
            'theme_color' => '#1d4ed8',
        ],
    ];

    public function __invoke(string $app): JsonResponse
    {
        abort_unless(isset(self::APPS[$app]), 404);

        $config = self::APPS[$app];

        return response()->json([
            'id' => "/{$app}",
            // Sur la plateforme : le nom de l'entreprise de l'adresse
            'name' => ((Tenancy::enabled() ? app(Tenancy::class)->company()?->name : null) ?? config('app.name')).' · '.$config['suffix'],
            'short_name' => $config['short_name'],
            'description' => $config['description'],
            'lang' => 'fr',
            'start_url' => "/{$app}?source=pwa",
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f8fafc',
            'theme_color' => $config['theme_color'],
            // Pas de /icons/ : sous Debian, Apache le réserve à ses propres icônes (Alias /icons/)
            'icons' => [
                ['src' => "/app-icons/{$app}-192.png", 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => "/app-icons/{$app}-512.png", 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => "/app-icons/{$app}-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
