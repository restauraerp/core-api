<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A tenant is one restaurant business. It sits *above* `locations`: a tenant
     * owns many locations (branches), and every other domain row belongs to
     * exactly one tenant.
     *
     * Not to be confused with `organizations`, which predates this and means a
     * corporate *customer* in the CRM sense (see customers.organization_id).
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Public-facing identifier. Doubles as the "restaurant code" typed on
            // the login form and the value accepted in the X-Tenant-ID header,
            // so it must stay URL- and human-safe.
            $table->string('slug')->unique();

            // Reserved for subdomain routing. Nothing resolves on it yet - the
            // middleware falls back to it only if a Host match is configured -
            // but having the column now means switching subdomain routing on
            // later needs no second migration.
            $table->string('domain')->nullable()->unique();

            // Plan tiers per docs/Pricing.md.
            $table->enum('plan', ['shared', 'dedicated', 'cloud'])->default('shared');

            $table->enum('status', ['trialing', 'active', 'suspended', 'cancelled'])
                ->default('trialing');

            // Outlet cap per plan: shared = 2, dedicated = 5, cloud = unlimited.
            // NULL means unlimited.
            $table->unsignedSmallInteger('max_outlets')->nullable()->default(2);

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
