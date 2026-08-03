<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reshapes the permission tables to what spatie/laravel-permission expects
     * with `teams` enabled and team_foreign_key = tenant_id.
     *
     * These tables were hand-written in the original schema migration rather
     * than published from the package, so they are missing the keys teams mode
     * relies on (roles had no unique at all; the pivots had no primary key).
     *
     * Note `permissions` and `role_has_permissions` get no tenant_id: teams
     * mode scopes *roles*, and keeps permissions as a global catalog. Our
     * permission names map 1:1 to hardcoded route guards in the frontend, so a
     * shared catalog is the correct model - tenants customise roles, not the
     * set of things the software can do.
     *
     * roles.tenant_id is NULLABLE on purpose: a NULL role is a global role
     * definition usable by every tenant (HasRoles::roles() matches
     * `tenant_id IS NULL OR tenant_id = <current>`). That is how super_admin
     * exists once instead of once per tenant.
     */
    public function up(): void
    {
        $defaultTenantId = DB::table('tenants')->orderBy('id')->value('id');

        // ── roles ────────────────────────────────────────────────────────
        Schema::table('roles', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $t->index('tenant_id', 'roles_tenant_id_index');
        });

        if ($defaultTenantId !== null) {
            DB::table('roles')->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);

            // super_admin is a platform role, not a restaurant role - hand it
            // back to the global (NULL) team.
            DB::table('roles')->where('name', 'super_admin')->update(['tenant_id' => null]);

            // The old catch-all `admin` role is the restaurant owner. Renamed
            // so it reads unambiguously next to the platform-level super_admin.
            DB::table('roles')->where('name', 'admin')->update(['name' => 'restaurant_admin']);
        }

        Schema::table('roles', function (Blueprint $t) {
            $t->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_id_name_guard_name_unique');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // ── model_has_roles / model_has_permissions ───────────────────────
        // Both get a NOT NULL tenant_id: the pivot is always concrete, even
        // when it points at a global role.
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $pivotKey) {
            // No ->after(): these pivots have no `id` column to anchor to.
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable();
            });

            if ($defaultTenantId !== null) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });

            Schema::table($table, function (Blueprint $t) use ($table, $pivotKey) {
                $t->index('tenant_id', "{$table}_tenant_id_index");
                $t->primary(['tenant_id', $pivotKey, 'model_id', 'model_type'], "{$table}_tenant_model_type_primary");
            });
        }

        // role_has_permissions never had a primary key either. Not a tenancy
        // problem, but it is the key Spatie assumes and it prevents duplicates.
        Schema::table('role_has_permissions', function (Blueprint $t) {
            $t->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    public function down(): void
    {
        Schema::table('role_has_permissions', function (Blueprint $t) {
            $t->dropPrimary('role_has_permissions_permission_id_role_id_primary');
        });

        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropPrimary("{$table}_tenant_model_type_primary");
                $t->dropIndex("{$table}_tenant_id_index");
                $t->dropColumn('tenant_id');
            });
        }

        Schema::table('roles', function (Blueprint $t) {
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('roles_tenant_id_name_guard_name_unique');
            $t->dropIndex('roles_tenant_id_index');
            $t->dropColumn('tenant_id');
        });

        DB::table('roles')->where('name', 'restaurant_admin')->update(['name' => 'admin']);
    }
};
