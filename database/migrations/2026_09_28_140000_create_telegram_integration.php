<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram notifications for riders.
 *
 * Each restaurant brings its own bot (made with @BotFather) so riders see the
 * restaurant's name, not ours. A rider links their Telegram account once,
 * through a single-use link the restaurant copies from the dashboard; from
 * then on, assigning them a delivery sends it to their chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One bot per restaurant.
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            // Encrypted at rest (the model casts it). Whoever holds it can send
            // as the restaurant's bot.
            $table->text('token');
            // The bot's own Telegram id. A different bot means every rider has
            // to start it again, so their links are cleared when it changes.
            $table->unsignedBigInteger('bot_user_id');
            $table->string('username', 64);
            $table->string('name', 128)->nullable();
            // Telegram sends it back on every webhook call, so a request that
            // lacks it did not come from Telegram.
            $table->string('webhook_secret', 64);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Single-use onboarding links, one per rider. Only a hash is kept.
        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Telegram chat ids exceed 32 bits and can be negative; a string
            // avoids both problems.
            $table->string('telegram_chat_id', 32)->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->timestamp('telegram_linked_at')->nullable();
        });

        // Where the assignment message went, so it can be scrubbed of the
        // customer's contact details once the delivery is over.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('telegram_sent_at')->nullable();
            $table->timestamp('telegram_redacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_message_id', 'telegram_sent_at', 'telegram_redacted_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_username', 'telegram_linked_at']);
        });

        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_bots');
    }
};
