<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use login links.
 *
 * A trial owner is provisioned without ever choosing a password, so there is
 * nothing for them to type on the login screen. They get a link instead: it
 * buys exactly one session, is spent the moment it is redeemed, and lands them
 * on a "set your password" screen.
 *
 * Only the hash is stored. A readable token here would be a password-equivalent
 * sitting in a table, and this one belongs to an account that cannot yet be
 * protected by a password at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_time_login_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalised so a redemption can establish tenant context without
            // loading the user first - the token is presented before anything
            // about the caller is known.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_login_tokens');
    }
};
