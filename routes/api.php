<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\PublicV1\PublicApiController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CourierController;
use App\Http\Controllers\Api\V1\CourierMessageController;
use App\Http\Controllers\Api\V1\CourierSpaceController;
use App\Http\Controllers\Api\V1\FieldReportController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HubController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\MerchantNotificationController;
use App\Http\Controllers\Api\V1\MerchantShopController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderActionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderExpenseController;
use App\Http\Controllers\Api\V1\OrderImportController;
use App\Http\Controllers\Api\V1\ParcelController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PayPlanController;
use App\Http\Controllers\Api\V1\PricingGridController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReturnSlipController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StorageContractController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsAppSettingsController;
use App\Http\Controllers\Api\V1\ZoneController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks des fournisseurs (authentifiés par signature)
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.handle');
});

// API publique des marchands (clé API, quota par clé) : contrat documenté dans public/docs/openapi.yaml
Route::prefix('public/v1')->name('public.')->middleware(['api.key', 'throttle:public-api'])->controller(PublicApiController::class)->group(function () {
    $read = 'api.scope:'.ApiScope::OrdersRead->value;
    $write = 'api.scope:'.ApiScope::OrdersWrite->value;

    Route::get('zones', 'zones')->name('zones');
    Route::get('hubs', 'hubs')->name('hubs');
    Route::post('quotes', 'quote')->name('quotes');
    Route::get('orders', 'orders')->middleware($read)->name('orders.index');
    Route::post('orders', 'store')->middleware([$write, 'idempotent'])->name('orders.store');
    Route::get('orders/{trackingCode}', 'show')->middleware($read)->name('orders.show');
    Route::post('orders/{trackingCode}/cancel', 'cancel')->middleware($write)->name('orders.cancel');
    Route::get('reports/summary', 'summary')->middleware($read)->name('reports.summary');
    Route::get('products', 'products')->middleware('api.scope:'.ApiScope::StockRead->value)->name('products');
});

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // Inscription des e-commerçants et mot de passe oublié : numéro vérifié par WhatsApp ou SMS
    Route::get('signup', [SignupController::class, 'options'])->name('signup.options');
    Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:verification-start')->name('signup.store');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:verification-start')->name('password.forgot');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
    Route::get('verifications/{token}', [VerificationController::class, 'show'])->middleware('throttle:90,1')->name('verifications.show');
    Route::post('verifications/{token}/sms', [VerificationController::class, 'sms'])->middleware('throttle:verification-sms')->name('verifications.sms');
    Route::post('verifications/{token}/code', [VerificationController::class, 'code'])->middleware('throttle:20,1')->name('verifications.code');

    // Page de commande publique d'un marchand (/b/{lien}) : catalogue, devis, commande
    Route::get('shops/{slug}', [ShopController::class, 'show'])->middleware('throttle:60,1')->name('shops.show');
    Route::post('shops/{slug}/quote', [ShopController::class, 'quote'])->middleware('throttle:60,1')->name('shops.quote');
    Route::post('shops/{slug}/orders', [ShopController::class, 'order'])->middleware('throttle:shop-order')->name('shops.order');

    // Nom, logo et accroche de l'entreprise de livraison (pages de connexion)
    Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');

    // Suivi public d'un colis (destinataire)
    Route::get('tracking/{code}', TrackingController::class)
        ->middleware('throttle:30,1')
        ->name('tracking.show');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('company/logo', [BrandingController::class, 'upload'])->name('company.logo.store');
        Route::delete('company/logo', [BrandingController::class, 'destroy'])->name('company.logo.destroy');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Notifications push de l'appareil (Web Push)
        Route::get('push', [PushSubscriptionController::class, 'show'])->name('push.show');
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
        Route::post('push/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1')->name('push.test');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::apiResource('companies', CompanyController::class)->except('destroy');
        Route::apiResource('users', UserController::class);

        // Référentiels
        Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
        Route::get('incident-reasons', [ReferenceController::class, 'incidentReasons'])->name('incident-reasons.index');
        Route::get('recipients', [ReferenceController::class, 'recipients'])->name('recipients.index');
        Route::post('quotes', QuoteController::class)->name('quotes.store');
        Route::get('hubs', [HubController::class, 'index'])->name('hubs.index');

        Route::middleware('can:settings.manage')->group(function () {
            Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
            Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
            Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
            Route::post('hubs', [HubController::class, 'store'])->name('hubs.store');
            Route::patch('hubs/{hub}', [HubController::class, 'update'])->name('hubs.update');

            Route::apiResource('pricing-grids', PricingGridController::class);
            Route::put('pricing-grids/{pricing_grid}/rules', [PricingGridController::class, 'syncRules'])->name('pricing-grids.rules');
            Route::put('pricing-grids/{pricing_grid}/surcharges', [PricingGridController::class, 'syncSurcharges'])->name('pricing-grids.surcharges');

            // Paie des livreurs : plans de rémunération
            Route::post('pay-plans/simulate', [PayPlanController::class, 'simulate'])->middleware('throttle:30,1')->name('pay-plans.simulate');
            Route::apiResource('pay-plans', PayPlanController::class);

            // WhatsApp
            Route::get('whatsapp/settings', [WhatsAppSettingsController::class, 'show'])->name('whatsapp.settings');
            Route::put('whatsapp/settings', [WhatsAppSettingsController::class, 'update'])->name('whatsapp.settings.update');
            Route::post('whatsapp/test', [WhatsAppSettingsController::class, 'test'])->middleware('throttle:10,1')->name('whatsapp.test');
            Route::post('whatsapp/templates/sync', [WhatsAppSettingsController::class, 'syncTemplates'])->name('whatsapp.templates.sync');
            Route::post('whatsapp/simulate', [WhatsAppSettingsController::class, 'simulate'])->middleware('throttle:60,1')->name('whatsapp.simulate');
        });

        // Remontées terrain des livreurs (notes, problèmes) : consultation et suivi par le dispatch
        Route::middleware('can:orders.dispatch')->prefix('field-reports')->name('field-reports.')->group(function () {
            Route::get('/', [FieldReportController::class, 'index'])->name('index');
            Route::get('counts', [FieldReportController::class, 'counts'])->name('counts');
            Route::post('{event}/handle', [FieldReportController::class, 'handle'])->name('handle');
            Route::post('{event}/reopen', [FieldReportController::class, 'reopen'])->name('reopen');
        });

        // Bons de retour groupés par marchand (droits vérifiés dans le contrôleur)
        Route::get('return-slips', [ReturnSlipController::class, 'index'])->name('return-slips.index');
        Route::post('return-slips', [ReturnSlipController::class, 'store'])->name('return-slips.store');
        Route::get('return-slips/{returnSlip}', [ReturnSlipController::class, 'show'])->name('return-slips.show');
        Route::post('return-slips/{returnSlip}/hand-over', [ReturnSlipController::class, 'handOver'])->middleware('throttle:30,1')->name('return-slips.hand-over');
        Route::post('return-slips/{returnSlip}/cancel', [ReturnSlipController::class, 'cancel'])->name('return-slips.cancel');
        Route::get('return-slips/{returnSlip}/proof/{kind}', [ReturnSlipController::class, 'proof'])->whereIn('kind', ['signature', 'photo'])->name('return-slips.proof');

        // Colis chez les livreurs (droits vérifiés dans le contrôleur)
        Route::get('parcels/held', [ParcelController::class, 'index'])->name('parcels.held');
        Route::get('parcels/held/counts', [ParcelController::class, 'counts'])->name('parcels.held.counts');
        Route::post('couriers/{courier}/parcels/receive', [ParcelController::class, 'receive'])->name('couriers.parcels.receive');

        // Journal des messages WhatsApp et SMS
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('messages/{message}/retry', [MessageController::class, 'retry'])->name('messages.retry');

        // Marchands
        Route::apiResource('merchants', MerchantController::class)->except('destroy');
        Route::post('merchants/{merchant}/users', [MerchantController::class, 'storeUser'])->name('merchants.users.store');
        Route::get('merchants/{merchant}/notifications', [MerchantNotificationController::class, 'show'])->name('merchants.notifications');
        Route::put('merchants/{merchant}/notifications', [MerchantNotificationController::class, 'update'])->name('merchants.notifications.update');

        // Livreurs (gestion par le personnel)
        Route::get('couriers/map', [CourierController::class, 'map'])->middleware('can:orders.dispatch')->name('couriers.map');
        Route::get('couriers/{courier}/track', [CourierController::class, 'track'])->middleware('can:orders.dispatch')->name('couriers.track');
        Route::get('couriers', [CourierController::class, 'index'])->middleware('can:orders.dispatch')->name('couriers.index');
        Route::get('couriers/{courier}', [CourierController::class, 'show'])->middleware('can:orders.dispatch')->name('couriers.show');
        Route::patch('couriers/{courier}', [CourierController::class, 'update'])->middleware('can:users.manage')->name('couriers.update');

        // Courses
        Route::get('orders/import/template', [OrderImportController::class, 'template'])->name('orders.import.template');
        Route::post('orders/import', [OrderImportController::class, 'store'])->middleware('throttle:20,1')->name('orders.import');
        Route::post('orders/bulk-assign', [OrderActionController::class, 'bulkAssign'])->name('orders.bulk-assign');
        Route::post('orders/bulk-confirm', [OrderActionController::class, 'bulkConfirm'])->name('orders.bulk-confirm');
        // Avant la ressource : « counts » n'est pas un identifiant de course
        Route::get('orders/counts', [OrderController::class, 'counts'])->name('orders.counts');
        Route::apiResource('orders', OrderController::class)->except('destroy');
        Route::post('orders/{order}/status', [OrderActionController::class, 'transition'])->name('orders.transition');
        Route::post('orders/{order}/assign', [OrderActionController::class, 'assign'])->name('orders.assign');
        Route::post('orders/{order}/decision', [OrderActionController::class, 'decide'])->name('orders.decision');
        Route::post('orders/{order}/lost', [OrderActionController::class, 'declareLost'])->name('orders.lost');
        Route::post('orders/{order}/notes', [OrderActionController::class, 'note'])->name('orders.notes');
        Route::post('orders/{order}/return-request', [OrderActionController::class, 'requestReturn'])->name('orders.return-request');
        Route::post('orders/{order}/attachments', [OrderActionController::class, 'storeAttachment'])->name('orders.attachments.store');
        Route::get('orders/{order}/messages', [MessageController::class, 'forOrder'])->name('orders.messages');
        Route::get('orders/{order}/courier-messages', [CourierMessageController::class, 'index'])->name('orders.courier-messages.index');
        Route::post('orders/{order}/courier-messages', [CourierMessageController::class, 'store'])->middleware('throttle:60,1')->name('orders.courier-messages.store');
        Route::post('orders/{order}/expenses', [OrderExpenseController::class, 'store'])->name('orders.expenses.store');
        Route::post('orders/{order}/expenses/{expense}/cancel', [OrderExpenseController::class, 'cancel'])->name('orders.expenses.cancel');
        Route::get('orders/{order}/attachments/{attachment}', [OrderActionController::class, 'showAttachment'])->name('orders.attachments.show');

        // Clés de l'API publique (le marchand pour lui-même, l'administration pour un marchand)
        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

        // Webhooks sortants
        Route::apiResource('webhooks', WebhookController::class)->except('show');
        Route::post('webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
        Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
        Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->middleware('throttle:30,1')->name('webhook-deliveries.redeliver');

        // Stock (droits vérifiés par ProductPolicy et dans les contrôleurs)
        Route::apiResource('products', ProductController::class);
        Route::post('products/{product}/photo', [ProductPhotoController::class, 'store'])->middleware('throttle:30,1')->name('products.photo.store');
        Route::delete('products/{product}/photo', [ProductPhotoController::class, 'destroy'])->name('products.photo.destroy');
        // Réglages de la page de commande du marchand
        Route::get('shop', [MerchantShopController::class, 'show'])->name('shop.show');
        Route::patch('shop', [MerchantShopController::class, 'update'])->name('shop.update');
        Route::get('stock/movements', [StockMovementController::class, 'index'])->name('stock.movements.index');
        Route::post('stock/movements', [StockMovementController::class, 'store'])->name('stock.movements.store');
        Route::apiResource('storage-contracts', StorageContractController::class)->except('show');

        Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');

        // Caisse et finances (droits vérifiés dans le contrôleur : finance.view / finance.manage)
        Route::prefix('finance')->name('finance.')->controller(FinanceController::class)->group(function () {
            Route::get('cash', 'cash')->name('cash');
            Route::get('couriers/{courier}/collections', 'collections')->name('couriers.collections');
            Route::post('couriers/{courier}/advances', 'storeAdvance')->name('couriers.advances.store');
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
            Route::get('messages', [CourierMessageController::class, 'mine'])->name('messages');
            Route::post('messages/{message}/ack', [CourierMessageController::class, 'acknowledge'])->name('messages.ack');
            Route::get('return-slips', [ReturnSlipController::class, 'mine'])->name('return-slips');
        });
    });
});
