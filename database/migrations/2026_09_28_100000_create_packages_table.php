<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom packages: a module list and outlet cap made for particular restaurants.
 *
 * The four tiers in config/plans.php are what the pricing page sells, and a
 * tenant's `plan` stays one of them - it is what billing, pricing and upgrade
 * links key on. A package sits beside it and, when set, replaces the tier's
 * *entitlements* only: which modules the restaurant has and how many outlets
 * it may open. A delivery-only cloud kitchen, say, or a Starter customer who
 * was sold one extra module.
 *
 * Platform-level, not tenant-scoped: one package can be assigned to any number
 * of restaurants, and is managed from the website's admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            // The default tier it was copied from, if any - for reference only.
            $table->string('based_on', 30)->nullable();
            $table->json('modules');
            // NULL means unlimited, as in config/plans.php.
            $table->unsignedInteger('outlets')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('plan')
                ->constrained('packages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });

        Schema::dropIfExists('packages');
    }
};
