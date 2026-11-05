<?php

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts that exist without signing in - a waiter credited with sales, a
 * rider given deliveries.
 *
 *   - roles.requires_login: whether a role's members sign in to the panel.
 *     The default for new users given the role; each user can still be
 *     switched (users.can_login).
 *   - users.email becomes optional: somebody who never signs in has no need
 *     of one. The (tenant_id, email) unique index stays; MySQL allows any
 *     number of NULLs in it.
 *   - existing restaurants get the new `waiter` role, and any other
 *     predefined role they are missing, without touching the roles they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('requires_login')->default(true)->after('guard_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Needs the permission catalogue; on a brand-new database there are
        // no restaurants yet and this does nothing.
        $provisioner = app(TenantProvisioner::class);
        Tenant::withTrashed()->with('package')->get()
            ->each(fn (Tenant $tenant) => $provisioner->addMissingRoles($tenant));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('requires_login');
        });
    }
};
