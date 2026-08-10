<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an account whose password was never chosen by its owner.
 *
 * Trial accounts are provisioned with a random password nobody is ever told,
 * and reached through a one-time link. Until the owner sets a password of their
 * own the account cannot be logged into the ordinary way, so the front is told
 * to insist on one before letting them get on with anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_set_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_set_password');
        });
    }
};
