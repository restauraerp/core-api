<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    // Locations API
    Route::get('location-types', [\App\Http\Controllers\LocationController::class, 'types']);
    Route::apiResource('locations', \App\Http\Controllers\LocationController::class);
    Route::apiResource('locations.halls', \App\Http\Controllers\HallController::class)->shallow();
    Route::apiResource('locations.tables', \App\Http\Controllers\TableController::class)->shallow();
    Route::apiResource('locations.cctv-cameras', \App\Http\Controllers\CctvCameraController::class)->shallow();

    // Website & CMS API
    Route::apiResource('website-settings', \App\Http\Controllers\WebsiteSettingController::class);
    Route::apiResource('social-links', \App\Http\Controllers\SocialLinkController::class);
    Route::apiResource('pages', \App\Http\Controllers\PageController::class);
    Route::apiResource('google-reviews', \App\Http\Controllers\GoogleReviewController::class);

    // Public Catalog API
    Route::apiResource('product-categories', \App\Http\Controllers\ProductCategoryController::class)->only(['index', 'show']);
    Route::apiResource('products', \App\Http\Controllers\ProductController::class)->only(['index', 'show']);

    // Auth & Users API
    Route::post('auth/register', [\App\Http\Controllers\AuthController::class, 'register']);
    Route::post('auth/login', [\App\Http\Controllers\AuthController::class, 'login']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
        Route::get('auth/me', [\App\Http\Controllers\AuthController::class, 'me']);

        Route::apiResource('users', \App\Http\Controllers\UserController::class);
        
        // Roles & Permissions API
        Route::apiResource('roles', \App\Http\Controllers\RoleController::class);
        Route::apiResource('permissions', \App\Http\Controllers\PermissionController::class);

        // HR API
        Route::apiResource('attendances', \App\Http\Controllers\AttendanceController::class);
        Route::apiResource('leaves', \App\Http\Controllers\LeaveController::class);
        Route::apiResource('payrolls', \App\Http\Controllers\PayrollController::class);

        // Catalog API (Protected Routes)
        Route::apiResource('product-categories', \App\Http\Controllers\ProductCategoryController::class)->except(['index', 'show']);
        Route::apiResource('tags', \App\Http\Controllers\TagController::class);
        Route::apiResource('products', \App\Http\Controllers\ProductController::class)->except(['index', 'show']);
        Route::apiResource('products.media', \App\Http\Controllers\ProductMediaController::class)->shallow();

        // Inventory API
        Route::apiResource('inventory-items', \App\Http\Controllers\InventoryItemController::class);
        Route::apiResource('storage-units', \App\Http\Controllers\StorageUnitController::class);
        Route::apiResource('storage-units.items', \App\Http\Controllers\StorageUnitItemController::class)->shallow();

        // Recipe & Stock Operations API
        Route::apiResource('recipes', \App\Http\Controllers\RecipeController::class);
        Route::apiResource('stock-transfers', \App\Http\Controllers\StockTransferController::class);
        Route::apiResource('stock-transfers.items', \App\Http\Controllers\StockTransferItemController::class)->shallow();
        Route::apiResource('waste-logs', \App\Http\Controllers\WasteLogController::class);

        // Procurement & Accounting API
        Route::apiResource('suppliers', \App\Http\Controllers\SupplierController::class);
        Route::apiResource('purchase-orders', \App\Http\Controllers\PurchaseOrderController::class);
        Route::apiResource('purchase-orders.items', \App\Http\Controllers\PurchaseOrderItemController::class)->shallow();
        Route::apiResource('purchase-returns', \App\Http\Controllers\PurchaseReturnController::class);
        Route::apiResource('accounting-ledgers', \App\Http\Controllers\AccountingLedgerController::class);
        Route::apiResource('expenses', \App\Http\Controllers\ExpenseController::class);
        Route::apiResource('tax-rules', \App\Http\Controllers\TaxRuleController::class);

        // CRM & Sales API
        Route::apiResource('organizations', \App\Http\Controllers\OrganizationController::class);
        Route::apiResource('customers', \App\Http\Controllers\CustomerController::class);
        Route::apiResource('loyalty-settings', \App\Http\Controllers\LoyaltySettingController::class);
        Route::apiResource('loyalty-transactions', \App\Http\Controllers\LoyaltyTransactionController::class);
        Route::apiResource('reservations', \App\Http\Controllers\ReservationController::class);
        Route::apiResource('quotations', \App\Http\Controllers\QuotationController::class);
        Route::apiResource('quotations.items', \App\Http\Controllers\QuotationItemController::class)->shallow();
        Route::apiResource('discounts', \App\Http\Controllers\DiscountController::class);
        Route::apiResource('orders', \App\Http\Controllers\OrderController::class);
        Route::apiResource('orders.items', \App\Http\Controllers\OrderItemController::class)->shallow();
        Route::apiResource('payments', \App\Http\Controllers\PaymentController::class);
        Route::apiResource('deliveries', \App\Http\Controllers\DeliveryController::class);

        // Support & System API
        Route::apiResource('support-tickets', \App\Http\Controllers\SupportTicketController::class);
        Route::apiResource('support-tickets.messages', \App\Http\Controllers\ChatMessageController::class)->shallow();
        Route::apiResource('notifications', \App\Http\Controllers\NotificationController::class);
        Route::apiResource('usage-logs', \App\Http\Controllers\UsageLogController::class);
    });
});
