<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website is published as a whole, in numbered versions.
 *
 * Until now each page was published on its own and the theme went live the
 * moment it was saved. Now site_pages and the site_* settings are only the
 * editor's draft, and Publish website copies all of it - every page, the
 * theme, the header and footer - into a site_versions row, which is what
 * visitors see. Older versions stay, so the site can be put back to one.
 *
 * Restaurants already have live pages, so each one's published pages and its
 * theme become version 1 and the site looks exactly as it did. Then the
 * per-page published copy goes: the version is the published copy now.
 *
 * site_pages.kind remembers which standard page a page is (menu, gallery...)
 * so it can be rebuilt from the restaurant's data; null for the owner's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('note', 200)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::table('site_pages', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('slug');
        });

        DB::table('site_pages')->where('is_home', true)->update(['kind' => 'home']);

        $this->backfill();

        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropColumn(['published', 'published_at']);
        });
    }

    private function backfill(): void
    {
        $tenants = DB::table('site_pages')->whereNotNull('published')->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $pages = DB::table('site_pages')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('published')
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            $settings = DB::table('website_settings')->where('tenant_id', $tenantId)
                ->whereIn('key', ['site_palette', 'site_font', 'site_header_order', 'site_footer_social', 'site_footer_text'])
                ->pluck('value', 'key');

            $snapshot = [
                'theme' => [
                    'palette' => $settings['site_palette'] ?? 'emerald',
                    'font' => $settings['site_font'] ?? 'modern',
                    'header_order_button' => ($settings['site_header_order'] ?? '1') !== '0',
                    'footer_social' => ($settings['site_footer_social'] ?? '1') !== '0',
                    'footer_text' => (string) ($settings['site_footer_text'] ?? ''),
                ],
                'pages' => $pages->map(fn ($page) => [
                    'id' => $page->id,
                    'kind' => $page->is_home ? 'home' : null,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'is_home' => (bool) $page->is_home,
                    'meta_title' => $page->meta_title,
                    'meta_description' => $page->meta_description,
                    'og_image' => $page->og_image,
                    'sections' => json_decode($page->published, true) ?? [],
                ])->values()->all(),
            ];

            $at = $pages->max('published_at') ?? now();
            $id = DB::table('site_versions')->insertGetId([
                'tenant_id' => $tenantId,
                'number' => 1,
                'snapshot' => json_encode($snapshot),
                'note' => 'The site as it was before versions',
                'published_at' => $at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('website_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => 'site_live_version'],
                ['value' => (string) $id, 'type' => 'string', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->dropColumn('kind');
        });

        DB::table('website_settings')->where('key', 'site_live_version')->delete();
        Schema::dropIfExists('site_versions');
    }
};
