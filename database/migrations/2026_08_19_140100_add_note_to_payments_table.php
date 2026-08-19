<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a payment looks the way it does.
 *
 * A row saying "3,500 by bKash" answers what but never why, and the why is
 * what somebody reconciling the till at midnight actually needs: the bKash
 * transaction id, the card's last four, which guest handed over cash for a
 * table of six. Settling a due order makes this load-bearing - a tab paid in
 * instalments is several payments that otherwise look identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('note')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
