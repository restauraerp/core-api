<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant website's measurement tags, and the server-side halves of them.
 *
 * The ids (GTM, GA4, Meta pixel, Clarity) are public - they end up in every
 * page's HTML anyway. The secrets that let core-api send conversions on the
 * restaurant's behalf - the Meta Conversions API token and the GA4 Measurement
 * Protocol API secret - are encrypted at rest and never sent back to a browser.
 *
 * Not part of the site's published versions: these are wiring, not content,
 * and a tag fixed in a hurry should not wait for the next Publish website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('gtm_id', 20)->nullable();
            $table->string('ga4_id', 20)->nullable();
            $table->string('meta_pixel_id', 20)->nullable();
            $table->string('clarity_id', 20)->nullable();
            $table->text('meta_capi_token')->nullable();
            $table->string('meta_test_event_code', 40)->nullable();
            $table->text('ga4_api_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_integrations');
    }
};
