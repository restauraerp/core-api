<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed a consumption log, and what it said before.
 *
 * Editing one of these is not like correcting a typo: the quantity is what
 * moved stock off the shelf, so changing it moves stock again. A figure that
 * can be altered with no trace is a figure nobody can rely on when the count
 * does not match - and "somebody must have mistyped it" is exactly the
 * conversation these rows exist to settle.
 *
 * The original quantity is kept rather than a full history. One correction of
 * a mistyped number is the case that actually happens; a restaurant editing
 * the same log five times has a different problem, and a full audit table
 * would be answering a question nobody has asked yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->foreignId('edited_by')->nullable()->after('logged_by')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
            // What it said before the first correction.
            $table->decimal('original_quantity', 10, 3)->nullable()->after('edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'original_quantity']);
            $table->dropConstrainedForeignId('edited_by');
        });
    }
};
