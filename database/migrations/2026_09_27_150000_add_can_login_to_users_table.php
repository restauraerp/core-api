<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an account may sign in at all.
 *
 * Some people need to exist as users - a rider to assign deliveries to, a
 * waiter to credit sales to, a salaried employee on the payroll - without ever
 * opening the app. Everyone existing keeps signing in exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_login')->default(true)->after('must_set_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_login');
        });
    }
};
