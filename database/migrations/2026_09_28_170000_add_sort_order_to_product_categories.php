<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order categories are shown in - the POS tabs, the online order page,
 * the storefront menu. Set by the restaurant from Catalog > Categories.
 *
 * Categories used to come back in creation order, so that is what every
 * restaurant is currently looking at. The backfill numbers them 1, 2, 3...
 * in exactly that order, per restaurant, so nothing moves on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->index(['tenant_id', 'sort_order']);
        });

        DB::statement('
            UPDATE product_categories c
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY tenant_id ORDER BY id) AS position
                FROM product_categories
            ) ranked ON ranked.id = c.id
            SET c.sort_order = ranked.position
        ');
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
