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
use App\Http\Controllers\DeliveryPayoutController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoLeadController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GoogleReviewController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LoyaltySettingController;
use App\Http\Controllers\LoyaltyTransactionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OneTimeLoginController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderDueController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerPayoutController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Platform\PlanController as PlatformPlanController;
use App\Http\Controllers\Platform\PackageController as PlatformPackageController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SiteBuilderController;
use App\Http\Controllers\GoogleBusinessController;
use App\Http\Controllers\MetaPagesController;
use App\Http\Controllers\SiteAccessTokenController;
use App\Http\Controllers\SiteDomainController;
use App\Http\Controllers\SiteIntegrationController;
use App\Http\Controllers\TelegramIntegrationController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\Platform\StatsController as PlatformStatsController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductMediaController;
use App\Http\Controllers\PublicOrderController;
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
use App\Http\Controllers\Venues\VenueBookingController;
use App\Http\Controllers\Venues\VenueController;
use App\Http\Controllers\Venues\VenuePackageController;
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
    // Locations API - reads only. The storefront and the online order page
    // list branches without signing in; changing them is in the signed-in
    // group below. These were whole apiResources here, which left creating,
    // editing and deleting branches, halls, tables and cameras open to anyone
    // who could name a restaurant code.
    Route::get('location-types', [LocationController::class, 'types']);
    Route::apiResource('locations', LocationController::class)->only(['index', 'show']);
    Route::apiResource('locations.halls', HallController::class)->shallow()->only(['index', 'show']);
    Route::apiResource('locations.tables', TableController::class)->shallow()->only(['index', 'show']);
    Route::apiResource('locations.cctv-cameras', CctvCameraController::class)->shallow()->only(['index', 'show']);

    // Settings API.
    //
    // Deliberately NOT behind module:website despite the table name.
    // website_settings holds currency_symbol and site_name, which POS, the
    // catalog and the settings screen all read on load - gating it took the
    // till offline on tiers that had simply not bought a storefront.
    //
    // Reads only here - the storefront and receipts read them unsigned-in.
    // Writes are in the signed-in group below.
    Route::apiResource('website-settings', WebsiteSettingController::class)->only(['index', 'show']);

    // Website & CMS API - the storefront itself, which is what the Website
    // module actually sells. Gated whole, reads included: a tier without it has
    // no public site to serve, not merely an admin screen it cannot open.
    //
    // Reads only here, for the public site. Writes are in the signed-in group
    // below, behind manage_website_content.
    Route::middleware('module:website')->group(function () {
        Route::apiResource('social-links', SocialLinkController::class)->only(['index', 'show']);
        Route::apiResource('pages', PageController::class)->only(['index', 'show']);
        Route::apiResource('google-reviews', GoogleReviewController::class)->only(['index', 'show']);
    });

    // Public Catalog API
    Route::apiResource('product-categories', ProductCategoryController::class)->only(['index', 'show']);
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);

    // There is deliberately no public route onto OrderController::store. It
    // trusts the price, discount, delivery charge and payment method it is
    // posted, which is right for staff at the till and wrong for anybody on
    // the internet: `storefront/orders` let an anonymous caller order at any
    // price and mark it paid. Customers order through `order/{slug}`
    // (PublicOrderController), which prices everything itself.

    // One order's invoice, to whoever holds the signed link the restaurant
    // shared with them.
    //
    // Public and unauthenticated because the customer has no account: the
    // signature is the credential, and it covers the order id and the expiry,
    // so the URL cannot be edited into somebody else's invoice. ResolveTenant
    // is skipped for the same reason demo-config skips it - a customer opening
    // a WhatsApp link has no restaurant to name - and the controller takes the
    // tenant from the order instead.
    Route::get('orders/{order}/invoice', [InvoiceController::class, 'show'])
        ->name('orders.invoice')
        ->middleware(['signed:relative', 'throttle:60,1'])
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    // The restaurant's online order link. Public: the tenant comes from the
    // restaurant code in the URL, not a header or token. Prices, tax and
    // payment are decided server-side - see PublicOrderController.
    Route::get('order/{slug}', [PublicOrderController::class, 'menu'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    Route::post('order/{slug}', [PublicOrderController::class, 'store'])
        ->middleware('throttle:10,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    // A restaurant's public website, as data: published pages and the
    // starter home. The tenant comes from the restaurant code in the URL.
    // Which restaurant a custom domain belongs to (asked by the front), and
    // whether a name may get a certificate (asked by Caddy's on-demand TLS).
    Route::get('site-domains/resolve', [SiteDomainController::class, 'resolve'])
        ->middleware('throttle:600,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    Route::get('site-domains/allowed', [SiteDomainController::class, 'allowed'])
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    Route::post('site/{code}/venue-enquiries', [PublicSiteController::class, 'venueEnquiry'])
        ->middleware('throttle:10,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    Route::post('site/{code}/reservations', [PublicSiteController::class, 'reserve'])
        ->middleware('throttle:10,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    // Google sends the owner back here after connecting their Business
    // Profile. Public: who it is for travels in the sealed state.
    Route::get('integrations/meta/callback', [MetaPagesController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    Route::get('integrations/google/callback', [GoogleBusinessController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);
    Route::get('site/{code}/{slug?}', [PublicSiteController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    // Updates from a restaurant's Telegram bot. Telegram is the caller; it
    // proves itself with the bot's webhook secret, and the bot says which
    // restaurant this is.
    Route::post('telegram/webhook/{bot}', [TelegramWebhookController::class, 'handle'])
        ->whereNumber('bot')
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

    // A customer's own orders, looked up by the tracking keys their browser
    // was handed when it placed them.
    Route::post('order/{slug}/my-orders', [PublicOrderController::class, 'mine'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware([ResolveTenant::class, EnforceSubscription::class]);

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

    // The same reading back out, so a half-finished walkthrough can be resumed
    // rather than restarted. Same exemptions, for the same reasons - and the
    // resume position is looked up against whoever the `ref` or the bearer token
    // says is asking, never against anything the caller names itself.
    Route::get('walkthrough/progress', [WalkthroughProgressController::class, 'show'])
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

            // Custom packages - a module list and outlet cap for particular
            // restaurants, beside the four default tiers.
            Route::apiResource('packages', PlatformPackageController::class);
            Route::put('tenants/{slug}/package', [PlatformTenantController::class, 'assignPackage']);

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
        Route::post('users/{user}/login-link', [UserController::class, 'loginLink']);

        // Roles & Permissions API
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class);

        // HR API
        Route::middleware('module:hr')->group(function () {
            Route::apiResource('attendances', AttendanceController::class);
            Route::apiResource('leaves', LeaveController::class);
            Route::apiResource('payrolls', PayrollController::class);
        });

        // Writes for the public reads registered above, now signed in and
        // permission-checked.
        // create_location is checked inside, after the plan's outlet cap.
        Route::post('locations', [LocationController::class, 'store']);
        Route::match(['put', 'patch'], 'locations/{location}', [LocationController::class, 'update'])->middleware('can:update_location');
        Route::delete('locations/{location}', [LocationController::class, 'destroy'])->middleware('can:delete_location');
        // A branch's halls, tables and cameras are part of editing the branch.
        Route::middleware('can:update_location')->group(function () {
            Route::apiResource('locations.halls', HallController::class)->shallow()->except(['index', 'show']);
            Route::apiResource('locations.tables', TableController::class)->shallow()->except(['index', 'show']);
            Route::apiResource('locations.cctv-cameras', CctvCameraController::class)->shallow()->except(['index', 'show']);
        });

        // Settings (Restaurant identity, slips, Facebook page, business day)
        // and the Website screens both write these.
        Route::apiResource('website-settings', WebsiteSettingController::class)
            ->except(['index', 'show'])
            ->middleware('can:manage_site_settings');

        Route::middleware(['module:website', 'can:manage_website_content'])->group(function () {
            Route::apiResource('social-links', SocialLinkController::class)->except(['index', 'show']);
            Route::apiResource('pages', PageController::class)->except(['index', 'show']);
            Route::apiResource('google-reviews', GoogleReviewController::class)->except(['index', 'show']);
        });

        // Catalog API (Protected Routes)
        Route::post('website-settings/logo', [WebsiteSettingController::class, 'uploadLogo'])->middleware('can:manage_site_settings');
        // Site builder: the restaurant's website pages, as drafts and
        // published versions. Website module, manage_website_content.
        Route::middleware('module:website')->group(function () {
            Route::get('site-builder/components', [SiteBuilderController::class, 'components']);
            Route::put('site-builder/theme', [SiteBuilderController::class, 'updateTheme']);
            Route::get('site-builder/templates', [SiteBuilderController::class, 'templates']);
            Route::get('site-builder/starters', [SiteBuilderController::class, 'starters']);
            Route::post('site-builder/starters/{key}', [SiteBuilderController::class, 'applyStarter'])->where('key', '[a-z_]+');
            Route::get('site-builder/smart-draft', [SiteBuilderController::class, 'smartDraft']);
            Route::get('site-builder/domain', [SiteDomainController::class, 'show']);
            Route::put('site-builder/domain', [SiteDomainController::class, 'update']);
            Route::post('site-builder/domain/verify', [SiteDomainController::class, 'verify'])->middleware('throttle:10,1');
            Route::post('site-builder/images', [SiteBuilderController::class, 'uploadImage']);
            Route::post('site-builder/standard-pages', [SiteBuilderController::class, 'standardPages']);
            Route::get('site-builder/access-tokens', [SiteAccessTokenController::class, 'index']);
            Route::post('site-builder/access-tokens', [SiteAccessTokenController::class, 'store'])->middleware('throttle:10,1');
            Route::delete('site-builder/access-tokens/{id}', [SiteAccessTokenController::class, 'destroy'])->whereNumber('id');
            Route::get('site-builder/integrations', [SiteIntegrationController::class, 'show']);
            Route::get('site-builder/meta', [MetaPagesController::class, 'show']);
            Route::post('site-builder/meta/connect', [MetaPagesController::class, 'connect']);
            Route::get('site-builder/meta/pages', [MetaPagesController::class, 'pages']);
            Route::put('site-builder/meta/page', [MetaPagesController::class, 'choosePage']);
            Route::post('site-builder/meta/sync', [MetaPagesController::class, 'sync'])->middleware('throttle:10,1');
            Route::delete('site-builder/meta', [MetaPagesController::class, 'disconnect']);
            Route::get('site-builder/google', [GoogleBusinessController::class, 'show']);
            Route::post('site-builder/google/connect', [GoogleBusinessController::class, 'connect']);
            Route::get('site-builder/google/locations', [GoogleBusinessController::class, 'locations']);
            Route::put('site-builder/google/location', [GoogleBusinessController::class, 'chooseLocation']);
            Route::post('site-builder/google/sync', [GoogleBusinessController::class, 'sync'])->middleware('throttle:10,1');
            Route::delete('site-builder/google', [GoogleBusinessController::class, 'disconnect']);
            Route::put('site-builder/integrations', [SiteIntegrationController::class, 'update']);
            Route::put('site-builder/page-order', [SiteBuilderController::class, 'reorder']);
            Route::post('site-builder/publish', [SiteBuilderController::class, 'publish']);
            Route::get('site-builder/versions', [SiteBuilderController::class, 'versions']);
            Route::post('site-builder/versions/{siteVersion}/make-live', [SiteBuilderController::class, 'makeLive']);
            Route::post('site-builder/versions/{siteVersion}/edit', [SiteBuilderController::class, 'loadVersion']);
            Route::apiResource('site-pages', SiteBuilderController::class)->except(['show']);
        });

        // Before the resource, so "reorder" is not read as a category id.
        Route::put('product-categories/reorder', [ProductCategoryController::class, 'reorder']);
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
        // Several items written off in one go - a cook closing the kitchen
        // reports eight things at once.
        Route::post('consumption-logs/batch', [ConsumptionLogController::class, 'storeBatch']);
        // update() is admin-only and adjusts the stock it moved; see the
        // controller. It was previously excluded outright.
        Route::apiResource('consumption-logs', ConsumptionLogController::class);
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
        Route::apiResource('incomes', IncomeController::class);
        Route::apiResource('tax-rules', TaxRuleController::class);

        // Customers, outside the CRM gate.
        //
        // Every tier lets a cashier type a customer's name and number into the
        // till, so every tier has to be able to see what it collected - and to
        // take it away again. Locked behind module:crm, a Starter restaurant
        // could fill this table for a year and never read a row of it. See
        // Modules::ESSENTIAL for where the line between "usable account" and
        // "upsell" is drawn; what CRM sells is the loyalty, reservations and
        // quotations below, not the address book.
        Route::apiResource('customers', CustomerController::class);
        Route::get('customers/{customer}/orders', [CustomerController::class, 'orders']);
        Route::get('customers-export', [CustomerController::class, 'export']);

        // CRM API
        Route::middleware('module:crm')->group(function () {
            Route::apiResource('organizations', OrganizationController::class);
            Route::apiResource('loyalty-settings', LoyaltySettingController::class);
            Route::apiResource('loyalty-transactions', LoyaltyTransactionController::class);
            Route::apiResource('reservations', ReservationController::class);
            Route::post('reservations/{reservation}/status', [ReservationController::class, 'status']);
            Route::post('reservations/{reservation}/called', [ReservationController::class, 'called']);

            // Venue booking: halls hired out for events.
            Route::apiResource('venues', VenueController::class)->except(['show']);
            Route::apiResource('venue-packages', VenuePackageController::class)->except(['show'])->parameters(['venue-packages' => 'package']);
            Route::post('venue-bookings/quote', [VenueBookingController::class, 'quote']);
            Route::apiResource('venue-bookings', VenueBookingController::class);
            Route::post('venue-bookings/{venueBooking}/status', [VenueBookingController::class, 'status']);
            Route::post('venue-bookings/{venueBooking}/called', [VenueBookingController::class, 'called']);
            Route::post('venue-bookings/{venueBooking}/payments', [VenueBookingController::class, 'addPayment']);
            Route::delete('venue-bookings/{venueBooking}/payments/{payment}', [VenueBookingController::class, 'removePayment']);
            Route::apiResource('quotations', QuotationController::class);
            Route::apiResource('quotations.items', QuotationItemController::class)->shallow();
        });

        // Sales API - part of Orders, which every tier includes. Discounts and
        // payments belong to taking money, not to CRM.
        Route::apiResource('discounts', DiscountController::class);
        Route::apiResource('orders', OrderController::class);
        // Mints the shareable invoice link. Staff only - creating the
        // authority to read an invoice is the restaurant's act, reading it is
        // the customer's.
        Route::post('orders/{order}/invoice-link', [InvoiceController::class, 'link']);

        // Third parties that send the restaurant orders and keep a cut.
        Route::apiResource('partners', PartnerController::class);
        Route::apiResource('partner-payouts', PartnerPayoutController::class)->only(['index', 'store', 'destroy']);

        // Owed money: putting an order on account, and collecting it later.
        Route::post('orders/{order}/due', [OrderDueController::class, 'markDue']);
        Route::post('orders/{order}/settle', [OrderDueController::class, 'settle']);
        Route::get('orders-trashed', [OrderController::class, 'trashed']);
        Route::post('orders/{order}/trash', [OrderController::class, 'trash']);
        Route::post('orders-trashed/{id}/restore', [OrderController::class, 'restore']);
        Route::apiResource('orders.items', OrderItemController::class)->shallow();
        Route::apiResource('payments', PaymentController::class);

        // Delivery API
        Route::middleware('module:delivery')->group(function () {
            Route::apiResource('deliveries', DeliveryController::class);
            // Delivery charges reported apart from sales revenue. Lives with the
            // delivery module rather than general reporting: a tenant without
            // delivery has no delivery charges to disburse.
            Route::get('reports/delivery-charges', [ReportController::class, 'deliveryCharges']);
            // Delivery charges paid back out to riders/couriers (booked as expenses).
            Route::apiResource('delivery-payouts', DeliveryPayoutController::class)->only(['index', 'store', 'destroy']);

            // Integration > Telegram: the restaurant's bot and its riders'
            // links. Owner only (manage_integrations).
            Route::get('integrations/telegram', [TelegramIntegrationController::class, 'show']);
            Route::put('integrations/telegram', [TelegramIntegrationController::class, 'update']);
            Route::delete('integrations/telegram', [TelegramIntegrationController::class, 'destroy']);
            Route::post('integrations/telegram/riders/{user}/link', [TelegramIntegrationController::class, 'link']);
            Route::delete('integrations/telegram/riders/{user}/link', [TelegramIntegrationController::class, 'unlink']);
            Route::post('integrations/telegram/riders/{user}/test', [TelegramIntegrationController::class, 'test']);
        });

        // Reporting API (aggregated server-side; see ReportController)
        Route::prefix('reports')->group(function () {
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('products', [ReportController::class, 'products']);
            Route::get('staff', [ReportController::class, 'staff']);
            Route::get('partners', [ReportController::class, 'partners']);
            Route::get('hourly', [ReportController::class, 'hourly']);
            Route::get('inventory', [ReportController::class, 'inventory']);
            Route::get('expenses', [ReportController::class, 'expenses']);
            Route::get('non-inventory-expenses', [ReportController::class, 'nonInventoryExpenses']);
            Route::get('consumable-expenses', [ReportController::class, 'consumableExpenses']);
            Route::get('all-inventory-expenses', [ReportController::class, 'allInventoryExpenses']);
            Route::get('profit', [ReportController::class, 'profit']);
            Route::get('accounting-by-header', [ReportController::class, 'accountingByHeader']);
        });

        // Support & System API
        Route::apiResource('support-tickets', SupportTicketController::class);
        Route::apiResource('support-tickets.messages', ChatMessageController::class)->shallow();
        Route::apiResource('notifications', NotificationController::class);
        Route::apiResource('usage-logs', UsageLogController::class);
    });
});
