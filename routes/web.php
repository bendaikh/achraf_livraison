<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ConfirmationCentreController;
use App\Http\Controllers\ConfirmationController;
use App\Http\Controllers\ConfirmationStatusController;
use App\Http\Controllers\DriverMissionController;
use App\Http\Controllers\DriverSavController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Shopify\ShopifyAuthController;
use App\Http\Controllers\Shopify\ShopifyIntegrationController;
use App\Http\Controllers\Shopify\ShopifyWebhookController;
use App\Http\Controllers\SiftWebhookController;
use App\Http\Controllers\SpeedafWebhookController;
use App\Http\Controllers\WhatsApp\WhatsAppAccountController;
use App\Http\Controllers\WhatsApp\WhatsAppConversationController;
use App\Http\Controllers\WhatsApp\WhatsAppMessageController;
use App\Http\Controllers\WhatsApp\WhatsAppMetaAuthController;
use App\Http\Controllers\WhatsApp\WhatsAppQuickReplyController;
use App\Http\Controllers\WhatsApp\WhatsAppTemplateController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth');
Route::get('/user', [AuthenticatedSessionController::class, 'me'])->middleware('auth');
Route::put('/profile', [ProfileController::class, 'update'])->middleware('auth');

/*
| Shopify app — OAuth + webhooks (must stay above the SPA catch-all).
*/
Route::get('/shopify/callback', [ShopifyAuthController::class, 'callback']);
Route::post('/shopify/webhooks', ShopifyWebhookController::class);

// Speedaf tracking push (signed HMAC-SHA256, per-company token in the URL)
Route::post('/speedaf/webhook/{token}', SpeedafWebhookController::class)->where('token', '[A-Za-z0-9]{20,64}');
Route::post('/sift/webhook/{token}', SiftWebhookController::class)->where('token', '[A-Za-z0-9]{20,64}');

/*
| WhatsApp / Meta — OAuth callback + webhooks (public).
*/
Route::get('/whatsapp/meta/callback', [WhatsAppMetaAuthController::class, 'callback']);
Route::get('/whatsapp/webhooks', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhooks', [WhatsAppWebhookController::class, 'receive']);

Route::middleware('auth')->group(function () {
    Route::get('/shopify/install', [ShopifyAuthController::class, 'install']);

    /*
    | Interface livreur — missions locales.
    */
    Route::middleware('driver.access')->prefix('api/driver')->group(function () {
        Route::get('/missions', [DriverMissionController::class, 'index']);
        Route::get('/missions/{order}', [DriverMissionController::class, 'show']);
        Route::post('/missions/{order}/deliver', [DriverMissionController::class, 'deliver']);
        Route::post('/missions/{order}/postpone', [DriverMissionController::class, 'postpone']);
        Route::post('/missions/{order}/no-answer', [DriverMissionController::class, 'noAnswer']);
        Route::post('/missions/{order}/fail', [DriverMissionController::class, 'fail']);
        // T7 — Retours & échanges
        Route::get('/sav', [DriverSavController::class, 'index']);
        Route::post('/sav/{sav}/action', [DriverSavController::class, 'action']);
    });

    /*
    | Affectation locale + livreurs (admin).
    */
    Route::middleware('admin.access')->group(function () {
        Route::prefix('api/assignment')->group(function () {
            Route::get('/orders', [AssignmentController::class, 'index']);
            Route::get('/orders/{order}', [AssignmentController::class, 'show']);
            Route::post('/assign', [AssignmentController::class, 'assign'])->middleware('can:orders.assign_driver');
        });

        // Livreurs (/api/drivers…) and Commandes (/api/orders…): see routes/api.php.
    });

    Route::middleware('admin.access')->prefix('api/confirmation')->group(function () {
        Route::get('/statuses', [ConfirmationStatusController::class, 'index']);
        Route::get('/orders', [ConfirmationController::class, 'index']);
        Route::get('/orders/{order}', [ConfirmationController::class, 'show']);
        Route::post('/orders/{order}/confirm', [ConfirmationController::class, 'confirm']);
        Route::post('/orders/{order}/no-answer', [ConfirmationController::class, 'noAnswer']);
        Route::post('/orders/{order}/postpone', [ConfirmationController::class, 'postpone']);
        Route::post('/orders/{order}/cancel', [ConfirmationController::class, 'cancel']);
        Route::put('/orders/{order}/internal-note', [ConfirmationController::class, 'updateInternalNote']);
        // T5 — Centre de confirmation
        Route::get('/stats', [ConfirmationCentreController::class, 'stats']);
        Route::get('/orders/{order}/siblings', [ConfirmationCentreController::class, 'siblings']);
        Route::post('/orders/{order}/calls', [ConfirmationCentreController::class, 'logCall']);
        Route::post('/orders/{order}/discounts', [ConfirmationCentreController::class, 'addDiscount'])->middleware('can:orders.discount');
        Route::delete('/orders/{order}/discounts/{discount}', [ConfirmationCentreController::class, 'removeDiscount'])->middleware('can:orders.discount');
    });

    /*
    | Future Paramètres → Statuts de confirmation (API ready, UI later).
    */
    Route::middleware('can:settings.manage')->prefix('api/settings/confirmation-statuses')->group(function () {
        Route::get('/', [ConfirmationStatusController::class, 'settingsIndex']);
        Route::post('/', [ConfirmationStatusController::class, 'store']);
        Route::put('/{confirmationStatus}', [ConfirmationStatusController::class, 'update']);
        Route::post('/reorder', [ConfirmationStatusController::class, 'reorder']);
    });

    Route::middleware('can:settings.manage')->prefix('api/integrations/shopify')->group(function () {
        Route::get('/', [ShopifyIntegrationController::class, 'status']);
        Route::post('/credentials', [ShopifyIntegrationController::class, 'saveCredentials']);
        Route::post('/connect', [ShopifyIntegrationController::class, 'connect']);
        Route::post('/disconnect', [ShopifyIntegrationController::class, 'disconnect']);
        Route::post('/sync', [ShopifyIntegrationController::class, 'sync']);
        Route::post('/reconcile', [ShopifyIntegrationController::class, 'reconcileNow']);
        Route::post('/webhooks', [ShopifyIntegrationController::class, 'registerWebhooks']);
        Route::get('/logs', [ShopifyIntegrationController::class, 'logs']);
        Route::post('/logs/{log}/retry', [ShopifyIntegrationController::class, 'retryLog']);
        Route::get('/orders', [ShopifyIntegrationController::class, 'orders']);
    });

    Route::middleware('can:whatsapp.access')->prefix('api/whatsapp')->group(function () {
        Route::get('/unread-count', [WhatsAppConversationController::class, 'unreadCount']);

        Route::get('/accounts', [WhatsAppAccountController::class, 'index']);
        Route::post('/accounts', [WhatsAppAccountController::class, 'store']);
        Route::put('/accounts/{account}', [WhatsAppAccountController::class, 'update']);
        Route::delete('/accounts/{account}', [WhatsAppAccountController::class, 'destroy']);
        Route::post('/accounts/{account}/sync', [WhatsAppAccountController::class, 'sync']);
        Route::post('/meta/credentials', [WhatsAppAccountController::class, 'saveMetaCredentials']);
        Route::post('/meta/connect', [WhatsAppAccountController::class, 'startMetaConnect']);
        Route::post('/meta/embedded-signup', [WhatsAppAccountController::class, 'completeEmbeddedSignup']);
        Route::get('/migration-checklist', [WhatsAppAccountController::class, 'migrationChecklist']);

        Route::get('/conversations', [WhatsAppConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [WhatsAppConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/read', [WhatsAppConversationController::class, 'markRead']);
        Route::post('/conversations/{conversation}/assign', [WhatsAppConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/resolve', [WhatsAppConversationController::class, 'resolve']);
        Route::post('/conversations/{conversation}/reopen', [WhatsAppConversationController::class, 'reopen']);
        Route::get('/orders/{order}/conversation', [WhatsAppConversationController::class, 'openForOrder']);

        Route::post('/conversations/{conversation}/messages', [WhatsAppMessageController::class, 'store']);
        Route::post('/conversations/{conversation}/messages/media', [WhatsAppMessageController::class, 'storeMedia']);
        Route::post('/conversations/{conversation}/messages/template', [WhatsAppMessageController::class, 'storeTemplate']);
        Route::get('/messages/{message}/media', [WhatsAppMessageController::class, 'media']);

        Route::get('/templates', [WhatsAppTemplateController::class, 'index']);
        Route::post('/templates', [WhatsAppTemplateController::class, 'store']);
        Route::post('/templates/sync', [WhatsAppTemplateController::class, 'sync']);

        Route::get('/quick-replies', [WhatsAppQuickReplyController::class, 'index']);
        Route::post('/quick-replies', [WhatsAppQuickReplyController::class, 'store']);
        Route::put('/quick-replies/{quickReply}', [WhatsAppQuickReplyController::class, 'update']);
        Route::delete('/quick-replies/{quickReply}', [WhatsAppQuickReplyController::class, 'destroy']);
    });
});

/*
| SPA shell — all UI pages are React routes (lazy-loaded).
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api(?:/|$)).*');
