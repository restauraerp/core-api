<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venue bookings are kept up to date by email: the enquiry received, the
 * booking confirmed or declined, each payment - sent by the website on
 * core-api's behalf (WebsiteNotifier). No phone or WhatsApp verification.
 * last_emailed_at shows staff the guest has been written to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->string('guest_email', 191)->nullable()->after('guest_phone');
            $table->timestamp('last_emailed_at')->nullable()->after('called_by');
        });
    }

    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropColumn(['guest_email', 'last_emailed_at']);
        });
    }
};
