<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's own domain for its website (tenants.domain, unused until
 * now), and when its DNS was confirmed to point at us. Only a verified domain
 * is served or given a certificate - an owner typing someone else's domain
 * must not make us answer for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('domain_verified_at')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('domain_verified_at');
        });
    }
};
