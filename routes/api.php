<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CourierController;
use App\Http\Controllers\Api\V1\CourierSpaceController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderActionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PricingGridController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // Suivi public d'un colis (destinataire)
    Route::get('tracking/{code}', TrackingController::class)
        ->middleware('throttle:30,1')
        ->name('tracking.show');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::apiResource('companies', CompanyController::class)->except('destroy');
        Route::apiResource('users', UserController::class);

        // Référentiels
        Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
        Route::get('incident-reasons', [ReferenceController::class, 'incidentReasons'])->name('incident-reasons.index');
        Route::get('recipients', [ReferenceController::class, 'recipients'])->name('recipients.index');
        Route::post('quotes', QuoteController::class)->name('quotes.store');

        Route::middleware('can:settings.manage')->group(function () {
            Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
            Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');

            Route::apiResource('pricing-grids', PricingGridController::class);
            Route::put('pricing-grids/{pricing_grid}/rules', [PricingGridController::class, 'syncRules'])->name('pricing-grids.rules');
            Route::put('pricing-grids/{pricing_grid}/surcharges', [PricingGridController::class, 'syncSurcharges'])->name('pricing-grids.surcharges');
        });

        // Marchands
        Route::apiResource('merchants', MerchantController::class)->except('destroy');
        Route::post('merchants/{merchant}/users', [MerchantController::class, 'storeUser'])->name('merchants.users.store');

        // Livreurs (gestion par le personnel)
        Route::get('couriers', [CourierController::class, 'index'])->middleware('can:orders.dispatch')->name('couriers.index');
        Route::get('couriers/{courier}', [CourierController::class, 'show'])->middleware('can:orders.dispatch')->name('couriers.show');
        Route::patch('couriers/{courier}', [CourierController::class, 'update'])->middleware('can:users.manage')->name('couriers.update');

        // Courses
        Route::post('orders/bulk-assign', [OrderActionController::class, 'bulkAssign'])->name('orders.bulk-assign');
        Route::apiResource('orders', OrderController::class)->except('destroy');
        Route::post('orders/{order}/status', [OrderActionController::class, 'transition'])->name('orders.transition');
        Route::post('orders/{order}/assign', [OrderActionController::class, 'assign'])->name('orders.assign');
        Route::post('orders/{order}/notes', [OrderActionController::class, 'note'])->name('orders.notes');
        Route::post('orders/{order}/return-request', [OrderActionController::class, 'requestReturn'])->name('orders.return-request');
        Route::post('orders/{order}/attachments', [OrderActionController::class, 'storeAttachment'])->name('orders.attachments.store');
        Route::get('orders/{order}/attachments/{attachment}', [OrderActionController::class, 'showAttachment'])->name('orders.attachments.show');

        Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');

        // Caisse et finances (droits vérifiés dans le contrôleur : finance.view / finance.manage)
        Route::prefix('finance')->name('finance.')->controller(FinanceController::class)->group(function () {
            Route::get('cash', 'cash')->name('cash');
            Route::get('couriers/{courier}/collections', 'collections')->name('couriers.collections');
            Route::get('couriers/{courier}/earnings', 'earnings')->name('couriers.earnings');
            Route::post('couriers/{courier}/adjustments', 'adjustCourier')->name('couriers.adjustments');
            Route::get('remittances', 'remittances')->name('remittances.index');
            Route::post('remittances', 'storeRemittance')->name('remittances.store');

            Route::get('merchants', 'merchants')->name('merchants.index');
            Route::get('merchants/{merchant}/ledger', 'ledger')->name('merchants.ledger');
            Route::post('merchants/{merchant}/adjustments', 'adjustMerchant')->name('merchants.adjustments');
            Route::get('payouts', 'payouts')->name('payouts.index');
            Route::post('payouts', 'storePayout')->name('payouts.store');
            Route::get('payouts/{payout}', 'showPayout')->name('payouts.show');
            Route::post('payouts/{payout}/pay', 'payPayout')->name('payouts.pay');
            Route::post('payouts/{payout}/cancel', 'cancelPayout')->name('payouts.cancel');

            Route::get('courier-payouts', 'courierPayouts')->name('courier-payouts.index');
            Route::post('courier-payouts', 'storeCourierPayout')->name('courier-payouts.store');
            Route::get('courier-payouts/{courierPayout}', 'showCourierPayout')->name('courier-payouts.show');
            Route::post('courier-payouts/{courierPayout}/pay', 'payCourierPayout')->name('courier-payouts.pay');
            Route::post('courier-payouts/{courierPayout}/cancel', 'cancelCourierPayout')->name('courier-payouts.cancel');
        });

        // Espace livreur
        Route::prefix('courier')->name('courier.')->middleware('role:courier')->group(function () {
            Route::get('missions', [CourierSpaceController::class, 'missions'])->name('missions');
            Route::post('assignments/{assignment}/accept', [CourierSpaceController::class, 'accept'])->name('assignments.accept');
            Route::post('assignments/{assignment}/refuse', [CourierSpaceController::class, 'refuse'])->name('assignments.refuse');
            Route::patch('status', [CourierSpaceController::class, 'updateStatus'])->name('status');
            Route::get('wallet', [FinanceController::class, 'wallet'])->name('wallet');
        });
    });
});
