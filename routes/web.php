<?php

use App\Http\Controllers\PwaManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/manifest/{app}.webmanifest', PwaManifestController::class)
    ->whereIn('app', ['marchand', 'livreur'])
    ->name('pwa.manifest');

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api(/|$)).*');
