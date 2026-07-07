<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ---------------------------------------------------------
        // LOCATIONS (Created first because users table needs it)
        // ---------------------------------------------------------
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type')->nullable()->comment('head_office or branch');
            $table->text('address')->nullable();
            $table->text('map_url')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // ALTER USERS (Add location_id to Laravel's default users table)
        // ---------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('id')->constrained('locations')->nullOnDelete();
        });

        // ---------------------------------------------------------
        // AUTHORIZATION (Roles & Permissions)
        // ---------------------------------------------------------
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
        });

        // ---------------------------------------------------------
        // WEBSITE CMS
        // ---------------------------------------------------------
        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('social_links', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->nullable();
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
        });
        Schema::create('google_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('author_name')->nullable();
            $table->integer('rating')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('time')->nullable();
            $table->boolean('is_displayed')->default(false);
        });

        // ---------------------------------------------------------
        // HR & EMPLOYEE MANAGEMENT
        // ---------------------------------------------------------
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date')->nullable();
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            
            $table->index('date', 'idx_attendances_date');
        });
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('month')->nullable();
            $table->integer('year')->nullable();
            $table->decimal('basic_salary', 10, 2)->nullable();
            $table->decimal('bonus', 10, 2)->default(0);
            $table->decimal('overtime_pay', 10, 2)->default(0);
            $table->decimal('deductions', 10, 2)->default(0);
            $table->decimal('net_pay', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // FACILITIES (Continued)
        // ---------------------------------------------------------
        Schema::create('location_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });
        Schema::create('halls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->integer('capacity')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->integer('capacity')->nullable();
            $table->timestamps();
        });
        Schema::create('cctv_cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->text('stream_url')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // CUSTOMERS & LOYALTY
        // ---------------------------------------------------------
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->unique()->nullable();
            $table->string('email')->nullable();
            $table->integer('loyalty_points')->default(0);
            $table->string('tier')->default('Bronze');
            $table->timestamps();
        });
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->integer('points')->nullable();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('points_per_amount', 10, 2)->nullable();
            $table->decimal('cash_per_point', 10, 2)->nullable();
            $table->json('tier_thresholds')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // PRODUCTS & CATALOG
        // ---------------------------------------------------------
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique()->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique()->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('is_active', 'idx_products_is_active');
        });
        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
        });
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // INVENTORY & SUPPLIERS
        // ---------------------------------------------------------
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('min_stock_level', 10, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity_required', 10, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('storage_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_unit_id')->constrained('storage_units')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('from_storage_id')->constrained('storage_units')->cascadeOnDelete();
            $table->foreignId('to_storage_id')->constrained('storage_units')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('waste_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('price', 10, 2)->nullable();
        });
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->decimal('total_refund', 10, 2)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // ORDERS & QUOTATIONS
        // ---------------------------------------------------------
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->nullable();
            $table->decimal('price', 10, 2)->nullable();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->foreignId('hall_id')->nullable()->constrained('halls')->nullOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->string('order_type')->nullable();
            $table->string('status')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at'], 'idx_orders_status_date');
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->text('notes')->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('method')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('address')->nullable();
            $table->decimal('delivery_charge', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            
            $table->index('status', 'idx_deliveries_status');
        });
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('hall_id')->nullable()->constrained('halls')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->dateTime('reservation_date')->nullable();
            $table->integer('guest_count')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            
            $table->index(['reservation_date', 'status'], 'idx_reservations_date_status');
        });

        // ---------------------------------------------------------
        // ACCOUNTING
        // ---------------------------------------------------------
        Schema::create('accounting_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('transaction_type')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_url')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------
        // SUPPORT & LOGS
        // ---------------------------------------------------------
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('sender_type')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->nullable();
            $table->string('target_table')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
            
            $table->index('created_at', 'idx_usage_logs_created_at');
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            
            $table->index(['user_id', 'is_read'], 'idx_notifications_user_read');
        });
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('usage_logs');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('accounting_ledgers');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('waste_logs');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_stock');
        Schema::dropIfExists('storage_units');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('loyalty_settings');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('cctv_cameras');
        Schema::dropIfExists('tables');
        Schema::dropIfExists('halls');
        Schema::dropIfExists('location_media');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('google_reviews');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('social_links');
        Schema::dropIfExists('website_settings');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        
        Schema::dropIfExists('locations');
    }
};
