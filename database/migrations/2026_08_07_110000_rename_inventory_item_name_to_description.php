<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inventory item has one name, and that is its title.
 *
 * The second `name` column was never a second name - it was used for the longer
 * "Whole Black Pepper" wording next to the short "Black Pepper" title, which is
 * a description. Renamed to say so, and widened to TEXT so it can hold one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->renameColumn('name', 'description');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->renameColumn('description', 'name');
        });
    }
};
