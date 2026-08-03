<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-tenant reach for the super_admin (platform) role.
     *
     * This flag exists because Spatie's teams mode cannot express it on its
     * own: model_has_roles.tenant_id is part of the composite primary key and
     * cannot be NULL, so a role assignment is always bound to exactly one
     * tenant. A platform operator needs to act across all of them.
     *
     * The flag is the authorisation source of truth (Gate::before consults it);
     * the super_admin role is the human-readable label that goes with it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('email_verified_at');
            $table->index('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_platform_admin']);
            $table->dropColumn('is_platform_admin');
        });
    }
};
