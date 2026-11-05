<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's Facebook Page and the Instagram business account linked to
 * it, connected so their latest posts can show on its website.
 *
 * The page token is what reads both; taken from a long-lived user token it
 * does not expire, and is encrypted at rest.
 *
 * Posts are stored rather than fetched per visitor: a website visit must not
 * wait on Meta, and Meta's image links expire after a while, so an hourly
 * sync keeps both the posts and their image links fresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->text('user_token');
            $table->timestamp('user_token_expires_at')->nullable();
            $table->string('user_name', 191)->nullable();
            $table->string('page_id', 40)->nullable();
            $table->string('page_name', 191)->nullable();
            $table->text('page_token')->nullable();
            $table->string('instagram_id', 40)->nullable();
            $table->string('instagram_username', 100)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('network', 20);
            $table->string('external_id', 100);
            $table->text('text')->nullable();
            $table->text('image_url')->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('media_type', 30)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'network', 'external_id']);
            $table->index(['tenant_id', 'network', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('meta_connections');
    }
};
