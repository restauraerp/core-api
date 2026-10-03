<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table bookings are kept up to date by email, like venue bookings: the
 * request received, approved, declined, cancelled - sent by the website on
 * core-api's behalf. No phone or WhatsApp verification; the phone stays the
 * number staff call. last_emailed_at shows staff the guest was written to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_email', 191)->nullable()->after('guest_phone');
            $table->timestamp('last_emailed_at')->nullable()->after('called_by');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['guest_email', 'last_emailed_at']);
        });
    }
};
