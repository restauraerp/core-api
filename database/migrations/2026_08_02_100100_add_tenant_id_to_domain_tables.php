<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every domain table carries its owning tenant.
     *
     * Child and pivot tables (order_items, product_tag, ...) get it too even
     * though it is derivable from the parent. That is deliberate: it lets raw
     * queries and reporting aggregates filter by tenant without a join, and it
     * turns a missed scope into a no-rows bug rather than a cross-tenant leak.
     *
     * Deliberately excluded:
     *   - permissions, role_has_permissions - Spatie treats permissions as a
     *     global catalog even in teams mode; a tenant_id there would never be
     *     read. Roles ARE per-tenant (see the teams migration).
     *   - roles, model_has_roles, model_has_permissions - handled by the teams
     *     migration, which needs different key shapes.
     *   - password_reset_tokens - handled by the unique-constraint migration,
     *     which has to rebuild its primary key.
     *   - cache, cache_locks, jobs, job_batches, failed_jobs, sessions,
     *     personal_access_tokens, migrations - framework infrastructure. Token
     *     and session rows derive their tenant from the owning user.
     */
    private array $tables = [
        'accounting_ledgers',
        'attendances',
        'cctv_cameras',
        'chat_messages',
        'customers',
        'deliveries',
        'discounts',
        'expenses',
        'google_reviews',
        'halls',
        'images',
        'inventory_item_location',
        'inventory_items',
        'inventory_stock',
        'leaves',
        'location_media',
        'location_product',
        'locations',
        'loyalty_settings',
        'loyalty_transactions',
        'notification_settings',
        'notifications',
        'order_items',
        'orders',
        'organizations',
        'pages',
        'payments',
        'payrolls',
        'product_categories',
        'product_media',
        'product_tag',
        'products',
        'purchase_items',
        'purchase_orders',
        'purchase_returns',
        'quotation_items',
        'quotations',
        'recipes',
        'reservations',
        'social_links',
        'stock_transfers',
        'storage_units',
        'suppliers',
        'support_tickets',
        'tables',
        'tags',
        'tax_rules',
        'usage_logs',
        'users',
        'waste_logs',
        'website_settings',
    ];

    public function up(): void
    {
        // 1. Add nullable so existing rows survive the ALTER.
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable();
            });
        }

        // 2. Adopt any pre-tenancy data into a default tenant. On a fresh
        //    migrate there is nothing to adopt and no tenant is created - the
        //    installation seeder provisions the first one instead.
        $defaultTenantId = $this->resolveDefaultTenantId();

        if ($defaultTenantId !== null) {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
                }
            }
        }

        // 3. Lock it down: NOT NULL, indexed, and cascading so tenant deletion
        //    actually removes the data rather than orphaning it.
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreign('tenant_id', "{$table}_tenant_id_foreign")
                    ->references('id')->on('tenants')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Returns the tenant existing rows should be adopted into, or null when the
     * database has no pre-tenancy data to adopt.
     */
    private function resolveDefaultTenantId(): ?int
    {
        $existing = DB::table('tenants')->orderBy('id')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $hasLegacyData = DB::table('users')->exists() || DB::table('locations')->exists();

        if (! $hasLegacyData) {
            return null;
        }

        return (int) DB::table('tenants')->insertGetId([
            'name' => config('app.name', 'Default Restaurant'),
            'slug' => 'default',
            'plan' => 'cloud',
            'status' => 'active',
            'max_outlets' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_tenant_id_foreign");
                $t->dropColumn('tenant_id');
            });
        }
    }
};
