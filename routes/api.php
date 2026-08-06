<?php

use App\Http\Controllers\AccountingLedgerController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CctvCameraController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DemoController;
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
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PermissionController;
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
use App\Http\Controllers\UsageLogController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WasteLogController;
use App\Http\Controllers\WebsiteSettingController;
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

    // Website & CMS API
    //
    // Gated as a whole, reads included: "Website" is sold as online presence,
    // so a tier without it has no storefront content to serve rather than an
    // admin screen it cannot open.
    Route::middleware('module:website')->group(function () {
        Route::apiResource('website-settings', WebsiteSettingController::class);
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

    // Auth & Users API
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

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

        // Procurement & Accounting API
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::apiResource('purchase-orders.items', PurchaseOrderItemController::class)->shallow();
        Route::apiResource('purchase-returns', PurchaseReturnController::class);
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
        });

        // Support & System API
        Route::apiResource('support-tickets', SupportTicketController::class);
        Route::apiResource('support-tickets.messages', ChatMessageController::class)->shallow();
        Route::apiResource('notifications', NotificationController::class);
        Route::apiResource('usage-logs', UsageLogController::class);
    });
});
