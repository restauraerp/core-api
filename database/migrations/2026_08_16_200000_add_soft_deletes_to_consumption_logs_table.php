<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('trashed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trashed_by');
            $table->dropSoftDeletes();
        });
    }
};
