<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['income', 'expense']);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('accounting_ledgers', function (Blueprint $table) {
            $table->foreignId('header_id')
                ->nullable()
                ->after('description')
                ->constrained('accounting_headers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('header_id');
        });

        Schema::dropIfExists('accounting_headers');
    }
};
