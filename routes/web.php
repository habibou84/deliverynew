<?php

use App\Http\Controllers\PwaManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/manifest/{app}.webmanifest', PwaManifestController::class)
    ->whereIn('app', ['marchand', 'livreur'])
    ->name('pwa.manifest');

// Documentation de l'API publique (spécification : public/docs/openapi.yaml)
Route::view('/developpeurs/api', 'api-docs')->name('api.docs');

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api(/|$)).*');
