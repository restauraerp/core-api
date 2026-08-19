<?php

use App\Http\Controllers\AccountingHeaderController;
use App\Http\Controllers\AccountingLedgerController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CctvCameraController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ConsumptionLogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoLeadController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GoogleReviewController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LoyaltySettingController;
use App\Http\Controllers\LoyaltyTransactionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OneTimeLoginController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Platform\PlanController as PlatformPlanController;
use App\Http\Controllers\Platform\StatsController as PlatformStatsController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductMediaController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseOrderItemController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\QuotationItemController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SocialLinkController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockTransferItemController;
use App\Http\Controllers\StorageUnitController;
use App\Http\Controllers\StorageUnitItemController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TaxRuleController;
use App\Http\Controllers\UpgradeLinkController;
use App\Http\Controllers\UsageLogController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalkthroughProgressController;
use App\Http\Controllers\WasteLogController;
use App\Http\Controllers\WebsiteSettingController;
use App\Http\Middleware\EnforceSubscription;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    // Locations API
    Route::get('location-types', [LocationController::class, 'types']);
    Route::apiResource('locations', LocationController::class);
    Route::apiResource('locations.halls', HallController::class)->shallow();
    Route::apiResource('locations.tables', TableController::class)->shallow();
    Route::apiResource('locations.cctv-cameras', CctvCameraController::class)->shallow();

    // Settings API.
    //
    // Deliberately NOT behind module:website despite the table name.
    // website_settings holds currency_symbol and site_name, which POS, the
    // catalog and the settings screen all read on load - gating it took the
    // till offline on tiers that had simply not bought a storefront.
    Route::apiResource('website-settings', WebsiteSettingController::class);

    // Website & CMS API - the storefront itself, which is what the Website
    // module actually sells. Gated whole, reads included: a tier without it has
    // no public site to serve, not merely an admin screen it cannot open.
    Route::middleware('module:website')->group(function () {
        Route::apiResource('social-links', SocialLinkController::class);
        Route::apiResource('pages', PageController::class);
        Route::apiResource('google-reviews', GoogleReviewController::class);
    });

    // Public Catalog API
    Route::apiResource('product-categories', ProductCategoryController::class)->only(['index', 'show']);
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);

    // Public Order API
    Route::post('storefront/orders', [OrderController::class, 'store']);

    // Demo credentials, for clients that were asked for a demo (the front's
    // /login?demo=true). 404s unless DEMO_MODE is on.
    //
    // ResolveTenant is skipped deliberately: it is appended to the whole api
    // group and 400s any unauthenticated request without an X-Tenant-ID header,
    // but which tenant the demo lives in is precisely what this call answers.
    Route::get('demo-config', [DemoController::class, 'show'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware(ResolveTenant::class);

    // The demo app's 60-second Lead, on its way to the website's Conversions
    // API. Same reasoning as demo-config for skipping tenant resolution: the
    // caller is an anonymous demo visitor, not a restaurant.
    Route::post('demo/lead', [DemoLeadController::class, 'store'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    // How far somebody got through a walkthrough or the video tutorials, on its
    // way to the website - which owns the customer records and the lifecycle
    // ladder, and should not have a second copy of either living here.
    //
    // Tenant resolution is skipped because a demo visitor has no tenant of their
    // own; a trial user's restaurant is read from their authenticated session
    // rather than from the request, so one restaurant cannot report progress
    // against another's account. Subscription enforcement is skipped too: a
    // walkthrough is exactly what somebody whose trial just lapsed should still
    // be able to finish.
    Route::post('walkthrough/progress', [WalkthroughProgressController::class, 'store'])
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    /*
    |----------------------------------------------------------------------
    | Platform API
    |----------------------------------------------------------------------
    |
    | Called by the marketing website, server to server, to start trials and
    | switch subscriptions on. Authenticated by the shared platform token, not
    | by a user - there is no user yet when a restaurant is being created, and
    | no customer should be able to activate their own subscription.
    |
    | ResolveTenant is skipped throughout: these calls are cross-tenant, and
    | creating a tenant cannot name one in advance.
    |
    */
    Route::prefix('platform')
        ->middleware(['platform', 'throttle:60,1'])
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class])
        ->group(function () {
            Route::get('plans', [PlatformPlanController::class, 'index']);

            // Billing state, counted, for the marketing site's daily report.
            Route::get('stats', [PlatformStatsController::class, 'index']);

            Route::post('tenants', [PlatformTenantController::class, 'store']);
            Route::get('tenants', [PlatformTenantController::class, 'index']);
            Route::get('tenants/{slug}', [PlatformTenantController::class, 'show']);
            Route::post('tenants/{slug}/subscribe', [PlatformTenantController::class, 'subscribe']);
            Route::post('tenants/{slug}/suspend', [PlatformTenantController::class, 'suspend']);
            Route::post('tenants/{slug}/reactivate', [PlatformTenantController::class, 'reactivate']);
            Route::delete('tenants/{slug}', [PlatformTenantController::class, 'trash']);
            Route::post('tenants/{slug}/restore', [PlatformTenantController::class, 'restore']);
            Route::delete('tenants/{slug}/purge', [PlatformTenantController::class, 'purge']);

            // Redeeming a trial owner's upgrade link.
            Route::post('upgrade-tokens/redeem', [UpgradeLinkController::class, 'redeem']);
            Route::post('tenants/{slug}/login-link', [PlatformTenantController::class, 'loginLink']);
            // An admin sending the owner a reset link instead of reading one
            // out to them over the phone.
            Route::post('tenants/{slug}/password-reset', [PlatformTenantController::class, 'sendPasswordReset']);
        });

    // Auth & Users API
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    // Redeeming a one-time login link. Skips tenant resolution for the same
    // reason demo-config does: the caller has not been told a restaurant code
    // yet, and the token is what carries the tenant.
    Route::post('auth/one-time-login', [OneTimeLoginController::class, 'store'])
        ->middleware('throttle:20,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    // "I have forgotten my password." Unauthenticated and tenant-less - the
    // address is all the caller has - and throttled hard, because it is the one
    // public route that sends mail on demand.
    Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:6,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Always writable: an account that must set a password would otherwise
        // be unable to, if its trial lapsed before the owner ever got in.
        Route::post('auth/password/set', [OneTimeLoginController::class, 'setPassword'])
            ->withoutMiddleware([EnforceSubscription::class]);

        // A trial owner asking to go and pay. Always writable: a lapsed tenant
        // is exactly who needs to reach the payment page.
        Route::post('billing/upgrade-link', [UpgradeLinkController::class, 'store'])
            ->withoutMiddleware([EnforceSubscription::class]);

        Route::apiResource('users', UserController::class);

        // Roles & Permissions API
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class);

        // HR API
        Route::middleware('module:hr')->group(function () {
            Route::apiResource('attendances', AttendanceController::class);
            Route::apiResource('leaves', LeaveController::class);
            Route::apiResource('payrolls', PayrollController::class);
        });

        // Catalog API (Protected Routes)
        Route::apiResource('product-categories', ProductCategoryController::class)->except(['index', 'show']);
        Route::apiResource('tags', TagController::class);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        Route::apiResource('products.media', ProductMediaController::class)->shallow();

        // Inventory API
        Route::apiResource('inventory-items', InventoryItemController::class);
        Route::apiResource('storage-units', StorageUnitController::class);
        Route::apiResource('storage-units.items', StorageUnitItemController::class)->shallow();

        // Recipe & Stock Operations API
        Route::apiResource('recipes', RecipeController::class);
        Route::apiResource('stock-transfers', StockTransferController::class);
        Route::apiResource('stock-transfers.items', StockTransferItemController::class)->shallow();
        Route::apiResource('waste-logs', WasteLogController::class);
        Route::apiResource('consumption-logs', ConsumptionLogController::class)->except(['update']);
        Route::get('consumption-logs-trashed', [ConsumptionLogController::class, 'trashed']);
        Route::post('consumption-logs/{consumptionLog}/trash', [ConsumptionLogController::class, 'trash']);
        Route::post('consumption-logs-trashed/{id}/restore', [ConsumptionLogController::class, 'restore']);

        // Procurement & Accounting API
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::apiResource('purchase-orders.items', PurchaseOrderItemController::class)->shallow();
        Route::apiResource('purchase-returns', PurchaseReturnController::class);
        Route::apiResource('accounting-headers', AccountingHeaderController::class);
        Route::apiResource('accounting-ledgers', AccountingLedgerController::class);
        Route::apiResource('expenses', ExpenseController::class);
        Route::apiResource('tax-rules', TaxRuleController::class);

        // CRM API
        Route::middleware('module:crm')->group(function () {
            Route::apiResource('organizations', OrganizationController::class);
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('loyalty-settings', LoyaltySettingController::class);
            Route::apiResource('loyalty-transactions', LoyaltyTransactionController::class);
            Route::apiResource('reservations', ReservationController::class);
            Route::apiResource('quotations', QuotationController::class);
            Route::apiResource('quotations.items', QuotationItemController::class)->shallow();
        });

        // Sales API - part of Orders, which every tier includes. Discounts and
        // payments belong to taking money, not to CRM.
        Route::apiResource('discounts', DiscountController::class);
        Route::apiResource('orders', OrderController::class);
        Route::get('orders-trashed', [OrderController::class, 'trashed']);
        Route::post('orders/{order}/trash', [OrderController::class, 'trash']);
        Route::post('orders-trashed/{id}/restore', [OrderController::class, 'restore']);
        Route::apiResource('orders.items', OrderItemController::class)->shallow();
        Route::apiResource('payments', PaymentController::class);

        // Delivery API
        Route::middleware('module:delivery')->group(function () {
            Route::apiResource('deliveries', DeliveryController::class);
        });

        // Reporting API (aggregated server-side; see ReportController)
        Route::prefix('reports')->group(function () {
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('products', [ReportController::class, 'products']);
            Route::get('hourly', [ReportController::class, 'hourly']);
            Route::get('inventory', [ReportController::class, 'inventory']);
            Route::get('expenses', [ReportController::class, 'expenses']);
            Route::get('non-inventory-expenses', [ReportController::class, 'nonInventoryExpenses']);
            Route::get('consumable-expenses', [ReportController::class, 'consumableExpenses']);
            Route::get('all-inventory-expenses', [ReportController::class, 'allInventoryExpenses']);
            Route::get('profit', [ReportController::class, 'profit']);
        });

        // Support & System API
        Route::apiResource('support-tickets', SupportTicketController::class);
        Route::apiResource('support-tickets.messages', ChatMessageController::class)->shallow();
        Route::apiResource('notifications', NotificationController::class);
        Route::apiResource('usage-logs', UsageLogController::class);
    });
});
