<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('role:admin')->get('/admin/test', fn () =>
        response()->json(['message' => 'Bienvenue Admin'])
    );

    Route::middleware('role:livreur')->get('/livreur/test', fn () =>
        response()->json(['message' => 'Bienvenue Livreur'])
    );

    Route::middleware('role:client')->get('/client/test', fn () =>
        response()->json(['message' => 'Bienvenue Client'])
    );
});
