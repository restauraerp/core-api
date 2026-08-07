<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which products involve the kitchen.
 *
 * Until now every order went through a cooking stage, including one that is a
 * bottle of water and a packet of crisps. A dish prepared to order needs the
 * kitchen; something sold as bought does not.
 *
 * Nothing is backfilled: every existing product starts unticked, so no order
 * reaches the kitchen until somebody says which products belong there. That is
 * a deliberate choice - guessing from `type` would quietly send the wrong
 * things to the kitchen display on the first day.
 *
 * The copy on `orders` is the answer for that order as it was placed, recorded
 * once at creation. The alternative - asking the line items on every read -
 * turns a list of fifty orders into fifty joins, and would let a later edit of
 * the catalogue rewrite the history of an order that has already been cooked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('needs_cooking')->default(false)->after('type');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('needs_cooking')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('needs_cooking');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('needs_cooking');
        });
    }
};
