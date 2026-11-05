<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages of a restaurant's website, built in the in-house site builder.
 *
 * A page is a list of sections - live ones filled from the restaurant's own
 * data (menu, online ordering, branches) and content ones the owner writes
 * (text, images). Stored as structured data, never HTML: every section type
 * and setting is checked against App\Support\Site\SiteSections on save, so no
 * custom code can reach the site.
 *
 * `draft` is what the editor saves; `published` is what visitors see, copied
 * from the draft on Publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('title', 120);
            $table->boolean('is_home')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('draft');
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};
