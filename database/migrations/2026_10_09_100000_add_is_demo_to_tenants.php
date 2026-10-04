<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo restaurants: for testing and sales presentations, never billed.
 *
 * They stay out of the subscription lists and the daily report, never expire,
 * and are never shown an upgrade prompt. The public demo restaurant (the
 * configured DEMO_TENANT_SLUG) is one, so it is marked here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status')->index();
        });

        DB::table('tenants')->where('slug', config('app.demo_tenant_slug'))->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
