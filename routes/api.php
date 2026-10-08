<?php

use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\AutomationRunController;
use App\Http\Controllers\Api\Campaigns\AudienceSegmentController;
use App\Http\Controllers\Api\Campaigns\ClientConsentController;
use App\Http\Controllers\Api\Campaigns\ClientTagController;
use App\Http\Controllers\Api\Campaigns\ClientVehicleController;
use App\Http\Controllers\Api\Campaigns\WhatsAppCampaignController;
use App\Http\Controllers\Api\CarrierController;
use App\Http\Controllers\Api\CentreController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClosingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryModeController;
use App\Http\Controllers\Api\DeliveryStatusController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LocalAssignmentController;
use App\Http\Controllers\Api\LogisticsPartnerController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderLifecycleController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\OzonIntegrationController;
use App\Http\Controllers\Api\OzonOrderController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SavController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShopifyOrderController;
use App\Http\Controllers\Api\SiftIntegrationController;
use App\Http\Controllers\Api\SiftOrderController;
use App\Http\Controllers\Api\SpeedafIntegrationController;
use App\Http\Controllers\Api\SpeedafOrderController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| JSON API consumed by the React SPA (admin screens). Session auth (same as the SPA) and
| admin role required; the driver space uses /api/driver/* (routes/web.php).
*/
Route::middleware(['web', 'auth', 'admin.access'])->group(function () {
    Route::get('meta', [MetaController::class, 'show']);

    // T7 — Retours / échanges (SAV)
    Route::middleware('can:sav.manage')->group(function () {
        Route::get('sav', [SavController::class, 'index']);
        Route::get('sav/meta', [SavController::class, 'meta']);
        Route::get('sav/custody', [SavController::class, 'custody']);
        Route::get('sav/orders', [SavController::class, 'searchOrders']);
        Route::get('sav/orders/{order}/prefill', [SavController::class, 'prefill']);
        Route::post('sav', [SavController::class, 'store']);
        Route::get('sav/{sav}', [SavController::class, 'show']);
        Route::post('sav/{sav}/assign', [SavController::class, 'assign']);
        Route::post('sav/{sav}/action', [SavController::class, 'action']);
        Route::get('orders/{order}/sav', [SavController::class, 'forOrder']);
    });

    // T9 — Clients
    Route::middleware('can:clients.view')->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/summary', [ClientController::class, 'summary']);
        Route::get('clients/{key}', [ClientController::class, 'show'])->where('key', '[0-9]+');
        Route::post('clients/{key}/notes', [ClientController::class, 'addNote'])->where('key', '[0-9]+');
    });
    Route::middleware('can:clients.block')->group(function () {
        Route::post('clients/{key}/block', [ClientController::class, 'block'])->where('key', '[0-9]+');
        Route::post('clients/{key}/unblock', [ClientController::class, 'unblock'])->where('key', '[0-9]+');
    });
    Route::middleware('can:clients.groups')->group(function () {
        Route::post('client-groups', [ClientController::class, 'storeGroup']);
        Route::put('client-groups/{group}', [ClientController::class, 'updateGroup']);
        Route::delete('client-groups/{group}', [ClientController::class, 'destroyGroup']);
        Route::post('client-groups/{group}/members', [ClientController::class, 'addMembers']);
        Route::delete('client-groups/{group}/members/{key}', [ClientController::class, 'removeMember']);
    });
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('can:dashboard.view');
    Route::get('centre', [CentreController::class, 'show'])->middleware('can:dashboard.view');

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update'])->middleware('can:settings.manage');

    // Paramètres → Statuts de livraison
    Route::get('delivery-statuses', [DeliveryStatusController::class, 'index']);
    Route::post('delivery-statuses', [DeliveryStatusController::class, 'store'])->middleware('can:settings.manage');
    Route::put('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'update'])->middleware('can:settings.manage');
    Route::delete('delivery-statuses/{deliveryStatus}', [DeliveryStatusController::class, 'destroy'])->middleware('can:settings.manage');
    Route::put('delivery-statuses/{deliveryStatus}/transitions', [DeliveryStatusController::class, 'updateTransitions'])->middleware('can:settings.manage');
    Route::get('status-transitions', [DeliveryStatusController::class, 'transitions']);

    // Paramètres → Partenaires logistiques (scoped to the user's company)
    Route::get('logistics-partners', [LogisticsPartnerController::class, 'index']);
    Route::post('logistics-partners', [LogisticsPartnerController::class, 'store'])->middleware('can:settings.manage');
    Route::put('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'update'])->middleware('can:settings.manage');
    Route::delete('logistics-partners/{logisticsPartner}', [LogisticsPartnerController::class, 'destroy'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/deactivate', [LogisticsPartnerController::class, 'deactivate'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/activate', [LogisticsPartnerController::class, 'activate'])->middleware('can:settings.manage');
    Route::post('logistics-partners/{logisticsPartner}/favorite', [LogisticsPartnerController::class, 'favorite'])->middleware('can:settings.manage');

    // Commandes → Affecter à livraison locale (bulk + fiche commande)
    Route::get('local-delivery/drivers', [LocalAssignmentController::class, 'drivers']);
    Route::post('local-delivery/assign', [LocalAssignmentController::class, 'assign'])->middleware('can:orders.assign_driver');

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/kanban', [OrderController::class, 'kanban']);
    Route::get('orders/actions', [OrderLifecycleController::class, 'actions']);
    Route::get('orders/commercials', [OrderLifecycleController::class, 'commercials']);
    Route::post('orders/flow', [OrderLifecycleController::class, 'store'])->middleware('can:orders.create');
    Route::post('orders/cancel', [OrderLifecycleController::class, 'cancelMany'])->middleware('can:orders.cancel');
    Route::post('orders/delete-drafts', [OrderLifecycleController::class, 'destroyMany'])->middleware('can:orders.delete_draft');
    Route::post('orders/bulk-status', [OrderController::class, 'bulkStatus']);
    Route::post('orders/assign-agent', [OrderController::class, 'assignAgent'])->middleware('can:orders.assign_agent');
    Route::post('orders', [OrderController::class, 'store'])->middleware('can:orders.create');
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::put('orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit');
    Route::post('orders/{order}/flow-retry', [OrderLifecycleController::class, 'retry'])->middleware('can:orders.create');
    Route::post('orders/{order}/cancel', [OrderLifecycleController::class, 'cancel'])->middleware('can:orders.cancel');
    Route::delete('orders/{order}/draft', [OrderLifecycleController::class, 'destroy'])->middleware('can:orders.delete_draft');
    Route::post('orders/{order}/status', [OrderController::class, 'changeStatus']);
    Route::post('orders/{order}/confirmation', [OrderController::class, 'changeConfirmation']);

    // Lignes de commande (modification interne, jamais poussée vers Shopify)
    Route::middleware('can:orders.edit_items')->group(function () {
        Route::post('orders/{order}/items', [OrderItemController::class, 'store']);
        Route::put('orders/{order}/items/{key}', [OrderItemController::class, 'update']);
        Route::delete('orders/{order}/items/{key}', [OrderItemController::class, 'destroy']);
        Route::post('orders/{order}/items/{key}/replace', [OrderItemController::class, 'replace']);
    });

    Route::post('orders/{order}/shopify-customer', [ShopifyOrderController::class, 'updateCustomer']);
    Route::post('orders/{order}/shopify-tracking', [ShopifyOrderController::class, 'sendTracking'])->middleware('can:orders.ship');
    Route::post('orders/{order}/shopify-retry', [ShopifyOrderController::class, 'retry'])->middleware('can:orders.edit_items');
    Route::post('orders/{order}/shopify-take-remote', [ShopifyOrderController::class, 'takeRemote'])->middleware('can:orders.edit_items');

    // Produits (catalogue Shopify synchronisé)
    Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view');
    Route::get('products/status', [ProductController::class, 'status'])->middleware('can:products.view');
    Route::post('products/sync', [ProductController::class, 'sync'])->middleware('can:products.sync');
    Route::post('products/webhooks', [ProductController::class, 'registerWebhooks'])->middleware('can:products.sync');
    Route::put('products/variants/{variant}', [ProductController::class, 'updateVariant'])->middleware('can:products.edit_shopify');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('can:products.edit_shopify');
    Route::post('products/{product}/shopify-retry', [ProductController::class, 'retry'])->middleware('can:products.edit_shopify');

    Route::get('drivers', [DriverController::class, 'index']);
    Route::get('drivers/active', [DriverController::class, 'active']);
    Route::post('drivers', [DriverController::class, 'store'])->middleware('can:drivers.manage');
    Route::get('drivers/{driver}', [DriverController::class, 'show']);
    Route::put('drivers/{driver}', [DriverController::class, 'update'])->middleware('can:drivers.manage');

    Route::get('missions', [MissionController::class, 'index']);
    Route::post('missions', [MissionController::class, 'store'])->middleware('can:drivers.manage');
    Route::get('missions/{mission}', [MissionController::class, 'show']);
    Route::put('missions/{mission}', [MissionController::class, 'update'])->middleware('can:drivers.manage');
    Route::post('missions/{mission}/status', [MissionController::class, 'changeStatus'])->middleware('can:drivers.manage');

    // Per-user UI preferences (current user = auth user, else user #1).
    Route::get('preferences/{key}', [PreferenceController::class, 'show']);
    Route::put('preferences/{key}', [PreferenceController::class, 'update']);

    // Intégrations → Speedaf (settings of the user's company) + Commandes actions
    Route::get('integrations/speedaf', [SpeedafIntegrationController::class, 'show'])->middleware('can:settings.manage');
    Route::put('integrations/speedaf', [SpeedafIntegrationController::class, 'update'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/test', [SpeedafIntegrationController::class, 'test'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/webhook/subscribe', [SpeedafIntegrationController::class, 'subscribeWebhook'])->middleware('can:settings.manage');
    Route::post('integrations/speedaf/sync', [SpeedafIntegrationController::class, 'sync'])->middleware('can:settings.manage');
    Route::get('delivery-modes', [DeliveryModeController::class, 'index']);
    Route::post('delivery-modes/{mode}/check', [DeliveryModeController::class, 'check'])->middleware('can:orders.ship')->where('mode', '[a-z0-9_-]+');
    Route::get('carriers', [CarrierController::class, 'index']);
    Route::post('carriers/labels', [CarrierController::class, 'labels']);
    Route::post('carriers/{carrier}/ship', [CarrierController::class, 'ship'])->middleware('can:orders.ship')->where('carrier', '[a-z0-9_-]+');
    Route::post('carriers/{carrier}/preview', [CarrierController::class, 'preview'])->middleware('can:orders.ship')->where('carrier', '[a-z0-9_-]+');

    // Intégrations → Transporteurs → Ozon Express (T14) + Paramètres → Transporteurs → Ozon (mappings)
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('integrations/ozon', [OzonIntegrationController::class, 'show']);
        Route::put('integrations/ozon', [OzonIntegrationController::class, 'update']);
        Route::post('integrations/ozon/test', [OzonIntegrationController::class, 'test']);
        Route::post('integrations/ozon/cities/sync', [OzonIntegrationController::class, 'syncCities']);
        Route::get('integrations/ozon/city-mappings', [OzonIntegrationController::class, 'cityMappings']);
        Route::put('integrations/ozon/city-mappings', [OzonIntegrationController::class, 'updateCityMapping']);
        Route::post('integrations/ozon/city-mappings/auto', [OzonIntegrationController::class, 'autoMatch']);
        Route::post('integrations/ozon/sync', [OzonIntegrationController::class, 'sync']);
        Route::get('integrations/ozon/logs', [OzonIntegrationController::class, 'logs']);
        Route::post('integrations/ozon/logs/{log}/retry', [OzonIntegrationController::class, 'retry']);
        Route::get('integrations/ozon/delivery-notes', [OzonIntegrationController::class, 'deliveryNotes']);
    });
    Route::get('ozon/cities', [OzonIntegrationController::class, 'cities']);

    // Intégrations → Transporteurs → Sift.ma (T8) + Paramètres → Transporteurs → Sift (mapping statuts)
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('integrations/sift', [SiftIntegrationController::class, 'show']);
        Route::put('integrations/sift', [SiftIntegrationController::class, 'update']);
        Route::post('integrations/sift/test', [SiftIntegrationController::class, 'test']);
        Route::post('integrations/sift/webhook/secret', [SiftIntegrationController::class, 'revealSecret']);
        Route::post('integrations/sift/webhook/regenerate', [SiftIntegrationController::class, 'regenerateSecret']);
        Route::post('integrations/sift/webhook/register', [SiftIntegrationController::class, 'registerWebhook']);
        Route::get('integrations/sift/webhook/events', [SiftIntegrationController::class, 'webhookEvents']);
        Route::post('integrations/sift/sync', [SiftIntegrationController::class, 'sync']);
        Route::get('integrations/sift/parcels', [SiftIntegrationController::class, 'parcels']);
        Route::get('integrations/sift/lookup', [SiftIntegrationController::class, 'lookup']);
        Route::get('integrations/sift/products', [SiftIntegrationController::class, 'products']);
        Route::get('integrations/sift/logs', [SiftIntegrationController::class, 'logs']);
        Route::post('integrations/sift/logs/{log}/retry', [SiftIntegrationController::class, 'retry']);
    });
    Route::post('sift/orders/{order}/refresh', [SiftOrderController::class, 'refresh']);
    Route::post('sift/orders/{order}/resync', [SiftOrderController::class, 'resync'])->middleware('can:orders.ship');
    Route::put('sift/orders/{order}', [SiftOrderController::class, 'update'])->middleware('can:orders.ship');
    Route::post('sift/orders/{order}/cancel', [SiftOrderController::class, 'cancel'])->middleware('can:orders.ship');
    Route::post('sift/orders/{order}/hide', [SiftOrderController::class, 'hide'])->middleware('can:orders.ship');
    Route::get('sift/orders/{order}/waybill', [SiftOrderController::class, 'waybill']);
    Route::post('sift/labels', [SiftOrderController::class, 'labels']);
    Route::post('ozon/orders/{order}/refresh', [OzonOrderController::class, 'refresh']);
    Route::post('ozon/orders/{order}/track', [OzonOrderController::class, 'track']);
    Route::post('ozon/delivery-notes', [OzonOrderController::class, 'createDeliveryNote'])->middleware('can:orders.ship');
    Route::post('ozon/labels', [OzonOrderController::class, 'labels']);
    Route::get('sav/{sav}/ozon', [OzonOrderController::class, 'savPreview'])->middleware('can:sav.manage');
    Route::post('sav/{sav}/ozon', [OzonOrderController::class, 'savSend'])->middleware('can:sav.manage');
    Route::post('speedaf/orders/send', [SpeedafOrderController::class, 'send'])->middleware('can:orders.ship');
    Route::post('speedaf/labels', [SpeedafOrderController::class, 'labels']);
    Route::post('speedaf/orders/{order}/cancel', [SpeedafOrderController::class, 'cancel'])->middleware('can:orders.ship');
    Route::post('speedaf/orders/{order}/sync', [SpeedafOrderController::class, 'sync']);
    Route::get('speedaf/orders/{order}/label', [SpeedafOrderController::class, 'label']);

    // Clôture du jour (caisse livreurs)
    Route::middleware('can:closings.manage')->group(function () {
        Route::get('closings', [ClosingController::class, 'index']);
        Route::get('closings/pending', [ClosingController::class, 'pending']);
        Route::post('closings', [ClosingController::class, 'store']);
    });

    // Équipe (T6): utilisateurs, services, performance, commissions
    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::get('services', [ServiceController::class, 'index']);
        Route::post('services', [ServiceController::class, 'store']);
        Route::put('services/{service}', [ServiceController::class, 'update']);
        Route::delete('services/{service}', [ServiceController::class, 'destroy']);
    });
    Route::get('team/performance', [TeamController::class, 'performance']);
    Route::get('team/commissions', [TeamController::class, 'commissions']);
    Route::post('team/commissions/transition', [TeamController::class, 'transition'])->middleware('can:commissions.manage');
    Route::post('team/commissions/monthly', [TeamController::class, 'monthly'])->middleware('can:commissions.manage');

    // WhatsApp Campaigns (module séparé des Automatisations)
    Route::middleware('can:campaigns.view')->prefix('whatsapp/campaigns')->group(function () {
        Route::get('stats', [WhatsAppCampaignController::class, 'stats']);
        Route::get('meta', [WhatsAppCampaignController::class, 'meta']);
        Route::get('search-clients', [WhatsAppCampaignController::class, 'searchClients']);
        Route::post('audience/preview', [WhatsAppCampaignController::class, 'audiencePreview']);
        Route::post('preview-message', [WhatsAppCampaignController::class, 'previewMessage']);
        Route::get('accounts/{account}/templates', [WhatsAppCampaignController::class, 'templatesForAccount']);
        Route::get('/', [WhatsAppCampaignController::class, 'index']);
        Route::get('{campaign}', [WhatsAppCampaignController::class, 'show']);
        Route::get('{campaign}/recipients', [WhatsAppCampaignController::class, 'recipients']);
        Route::get('{campaign}/confirm-summary', [WhatsAppCampaignController::class, 'confirmSummary']);
    });
    Route::middleware('can:campaigns.manage')->prefix('whatsapp/campaigns')->group(function () {
        Route::post('/', [WhatsAppCampaignController::class, 'store']);
        Route::put('{campaign}', [WhatsAppCampaignController::class, 'update']);
        Route::post('{campaign}/duplicate', [WhatsAppCampaignController::class, 'duplicate']);
        Route::post('{campaign}/archive', [WhatsAppCampaignController::class, 'archive']);
    });
    Route::middleware('can:campaigns.send')->prefix('whatsapp/campaigns')->group(function () {
        Route::post('{campaign}/send', [WhatsAppCampaignController::class, 'send']);
        Route::post('{campaign}/pause', [WhatsAppCampaignController::class, 'pause']);
        Route::post('{campaign}/resume', [WhatsAppCampaignController::class, 'resume']);
        Route::post('{campaign}/retry-failed', [WhatsAppCampaignController::class, 'retryFailed']);
    });

    Route::middleware('can:clients.tags')->group(function () {
        Route::get('client-tags', [ClientTagController::class, 'index']);
        Route::post('client-tags', [ClientTagController::class, 'store']);
        Route::put('client-tags/{tag}', [ClientTagController::class, 'update']);
        Route::delete('client-tags/{tag}', [ClientTagController::class, 'destroy']);
        Route::get('clients/{key}/tags', [ClientTagController::class, 'forClient'])->where('key', '[0-9]+');
        Route::post('clients/{key}/tags', [ClientTagController::class, 'assign'])->where('key', '[0-9]+');
        Route::delete('clients/{key}/tags/{tag}', [ClientTagController::class, 'remove'])->where('key', '[0-9]+');
        Route::put('clients/{key}/tags', [ClientTagController::class, 'sync'])->where('key', '[0-9]+');
    });

    Route::middleware('can:clients.view')->group(function () {
        Route::get('clients/{key}/vehicles', [ClientVehicleController::class, 'index'])->where('key', '[0-9]+');
        Route::post('clients/{key}/vehicles', [ClientVehicleController::class, 'store'])->where('key', '[0-9]+');
        Route::put('clients/{key}/vehicles/{vehicle}', [ClientVehicleController::class, 'update'])->where('key', '[0-9]+');
        Route::delete('clients/{key}/vehicles/{vehicle}', [ClientVehicleController::class, 'destroy'])->where('key', '[0-9]+');
        Route::get('clients/{key}/whatsapp-consent', [ClientConsentController::class, 'show'])->where('key', '[0-9]+');
        Route::put('clients/{key}/whatsapp-consent', [ClientConsentController::class, 'update'])->where('key', '[0-9]+');
    });

    Route::middleware('can:campaigns.view')->group(function () {
        Route::get('audience-segments', [AudienceSegmentController::class, 'index']);
        Route::get('audience-segments/{segment}/count', [AudienceSegmentController::class, 'count']);
    });
    Route::middleware('can:campaigns.manage')->group(function () {
        Route::post('audience-segments', [AudienceSegmentController::class, 'store']);
        Route::put('audience-segments/{segment}', [AudienceSegmentController::class, 'update']);
        Route::delete('audience-segments/{segment}', [AudienceSegmentController::class, 'destroy']);
    });

    // Automatisations (moteur générique QUAND → SI → ALORS → ATTENDRE → ACTIONS)
    Route::middleware('can:automations.view')->group(function () {
        Route::get('automations/catalog', [AutomationController::class, 'catalog']);
        Route::get('automations/stats', [AutomationController::class, 'stats']);
        Route::get('automations/templates', [AutomationController::class, 'templates']);
        Route::get('automations', [AutomationController::class, 'index']);
        Route::get('automations/{automation}', [AutomationController::class, 'show']);
        Route::get('automation-runs', [AutomationRunController::class, 'index']);
        Route::get('automation-runs/{run}', [AutomationRunController::class, 'show']);
    });
    Route::middleware('can:automations.manage')->group(function () {
        Route::post('automations', [AutomationController::class, 'store']);
        Route::put('automations/{automation}', [AutomationController::class, 'update']);
        Route::post('automations/{automation}/duplicate', [AutomationController::class, 'duplicate']);
        Route::post('automations/{automation}/activate', [AutomationController::class, 'activate']);
        Route::post('automations/{automation}/pause', [AutomationController::class, 'pause']);
        Route::post('automations/{automation}/archive', [AutomationController::class, 'archive']);
        Route::post('automations/templates/install', [AutomationController::class, 'installTemplate']);
        Route::post('automations/{automation}/run', [AutomationController::class, 'runManual']);
        Route::post('automation-runs/{run}/retry', [AutomationRunController::class, 'retry']);
    });
    Route::post('automations/{automation}/test', [AutomationController::class, 'test'])->middleware('can:automations.test');
});
