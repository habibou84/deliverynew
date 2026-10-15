<?php

use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\PwaManifestController;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

Route::get('/manifest/{app}.webmanifest', PwaManifestController::class)
    ->whereIn('app', ['marchand', 'livreur'])
    ->name('pwa.manifest');

// Logo de l'entreprise de livraison (pages de connexion, en-têtes)
Route::get('/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');
Route::get('/produits/{product}/photo', [ProductPhotoController::class, 'show'])->name('products.photo');

// Contrôle d'un domaine personnalisé : l'entreprise reconnue à cette adresse
Route::get('/.well-known/domain-check', fn () => response()->json([
    'slug' => app(Tenancy::class)->company()?->slug,
]))->middleware('throttle:60,1')->name('domain.check');

// Moteurs de recherche : tout est ouvert en production, tout est fermé ailleurs (préproduction)
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow:".(config('platform.indexable') ? '' : ' /')."\n")
    ->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');

// Politique de confidentialité (exigée par Meta pour WhatsApp) et conditions générales, sur toutes les adresses
Route::view('/confidentialite', 'legal.privacy')->name('privacy');
Route::view('/conditions', 'legal.terms')->name('terms');

// Documentation de l'API publique (spécification : public/docs/openapi.yaml)
Route::view('/developpeurs/api', 'api-docs')->name('api.docs');

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api(/|$)).*');
