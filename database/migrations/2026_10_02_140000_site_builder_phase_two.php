<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site builder, second phase.
 *
 *   - site_pages: what search engines and link previews show for a page.
 *   - reservations: the website's Reservations section takes real bookings
 *     now (the old storefront booking page only pretended to). A request
 *     from the site arrives as `pending`, marked source = 'website', with
 *     whatever the guest wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            $table->string('meta_title', 70)->nullable()->after('title');
            $table->string('meta_description', 160)->nullable()->after('meta_title');
            $table->string('og_image', 255)->nullable()->after('meta_description');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->text('notes')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['source', 'notes']);
        });

        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'og_image']);
        });
    }
};
