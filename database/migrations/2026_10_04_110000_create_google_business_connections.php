<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's Google Business Profile, connected so its reviews and rating
 * come in by themselves.
 *
 * The refresh token is what lets core-api read the profile later without the
 * owner; encrypted at rest. One profile location per restaurant: the one its
 * website shows reviews for.
 *
 * Reviews land in google_reviews alongside any typed in by hand, marked by
 * source and keyed by Google's review id so a sync updates rather than
 * duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_business_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('google_email', 191)->nullable();
            // "accounts/123/locations/456", as the reviews API addresses it.
            $table->string('location', 191)->nullable();
            $table->string('location_title', 191)->nullable();
            $table->string('maps_uri', 500)->nullable();
            $table->string('review_uri', 500)->nullable();
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('total_reviews')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('google_reviews', function (Blueprint $table) {
            $table->string('source', 20)->default('manual');
            $table->string('external_id', 191)->nullable();
            $table->string('author_photo_url', 500)->nullable();
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('google_reviews', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn(['source', 'external_id', 'author_photo_url']);
        });
        Schema::dropIfExists('google_business_connections');
    }
};
