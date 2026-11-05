<?php

use App\Support\Site\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleans the HTML already stored in custom website pages.
 *
 * Until now anyone could write a page (the route was public) and nothing was
 * cleaned, while the storefront renders the HTML as it is. New saves are
 * cleaned by PageController; this does the same once for what is there.
 * Formatting is kept, scripts and handlers are not. Not reversible - the
 * removed parts were never meant to be there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pages')->whereNotNull('content')->orderBy('id')->chunkById(100, function ($pages) {
            foreach ($pages as $page) {
                $clean = HtmlSanitizer::clean($page->content);

                if ($clean !== $page->content) {
                    DB::table('pages')->where('id', $page->id)->update(['content' => $clean]);
                }
            }
        });
    }

    public function down(): void {}
};
