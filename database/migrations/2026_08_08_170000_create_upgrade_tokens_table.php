<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a signed-in trial owner asked to upgrade.
 *
 * The payment page lives on the marketing site, which has no session for the
 * customer and no way to tell which restaurant is asking. A tenant slug in the
 * URL would let anyone raise an order against someone else's restaurant, so
 * the front asks the API for one of these instead: minted for the authenticated
 * user, redeemed once by the website over the platform channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_tokens');
    }
};
