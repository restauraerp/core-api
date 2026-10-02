<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Models\SiteVersion;
use App\Models\WebsiteSetting;
use App\Support\Branding\RestaurantBranding;
use App\Support\Site\SiteDraft;
use App\Support\Site\SiteSections;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The site builder's API. Behind module:website and manage_website_content.
 *
 * Everything here is a draft - the pages, adding and deleting them, the
 * theme, the header and footer. Visitors see none of it until Publish
 * website, which copies the whole site into a numbered SiteVersion and makes
 * it live. Earlier versions are kept: the site can be put back to one, or one
 * loaded into the editor to work from.
 *
 * A restaurant that has never opened the builder still has a site - the
 * public side serves the standard pages drafted from its data - and the first
 * visit here turns those into real, editable pages.
 */
class SiteBuilderController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /** GET /site-builder/components - what can be placed, and each one's settings. */
    public function components(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'components' => SiteSections::definitions($this->modules()),
            'palettes' => SiteSections::PALETTES,
            'fonts' => SiteSections::FONTS,
            'theme' => self::theme(),
        ]);
    }

    /**
     * PUT /site-builder/theme - the site's colour palette, type style, header
     * and footer. A draft, like the pages: live on the next Publish website.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'palette' => ['required', Rule::in(SiteSections::PALETTES)],
            'font' => ['required', Rule::in(SiteSections::FONTS)],
            // The header and footer every page shares.
            'header_order_button' => ['sometimes', 'boolean'],
            'footer_social' => ['sometimes', 'boolean'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/<[^>]*>/'],
        ], [
            'footer_text.not_regex' => 'The footer text cannot contain HTML.',
        ]);

        $this->storeTheme($validated);

        return response()->json(['theme' => self::theme(), 'site' => $this->status()]);
    }

    /** @param  array<string, mixed>  $theme  any of theme()'s keys */
    private function storeTheme(array $theme): void
    {
        $store = fn (string $key, string $value) => WebsiteSetting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);

        if (array_key_exists('palette', $theme)) {
            $store('site_palette', $theme['palette']);
        }
        if (array_key_exists('font', $theme)) {
            $store('site_font', $theme['font']);
        }
        if (array_key_exists('header_order_button', $theme)) {
            $store('site_header_order', $theme['header_order_button'] ? '1' : '0');
        }
        if (array_key_exists('footer_social', $theme)) {
            $store('site_footer_social', $theme['footer_social'] ? '1' : '0');
        }
        if (array_key_exists('footer_text', $theme)) {
            $store('site_footer_text', trim((string) $theme['footer_text']));
        }
    }

    /**
     * The current restaurant's draft site-wide settings - theme, header and
     * footer - with defaults for any never set.
     *
     * @return array{palette: string, font: string, header_order_button: bool, footer_social: bool, footer_text: string}
     */
    public static function theme(): array
    {
        $settings = WebsiteSetting::whereIn('key', [
            'site_palette', 'site_font', 'site_header_order', 'site_footer_social', 'site_footer_text',
        ])->pluck('value', 'key');

        return self::themeFrom([
            'palette' => $settings['site_palette'] ?? null,
            'font' => $settings['site_font'] ?? null,
            'header_order_button' => ($settings['site_header_order'] ?? '1') !== '0',
            'footer_social' => ($settings['site_footer_social'] ?? '1') !== '0',
            'footer_text' => $settings['site_footer_text'] ?? '',
        ]);
    }

    /** @return array{palette: string, font: string, header_order_button: bool, footer_social: bool, footer_text: string} */
    public static function defaultTheme(): array
    {
        return self::themeFrom([]);
    }

    /**
     * A theme with every key present and valid - for one read from settings
     * or from a published version.
     *
     * @return array{palette: string, font: string, header_order_button: bool, footer_social: bool, footer_text: string}
     */
    public static function themeFrom(array $theme): array
    {
        return [
            'palette' => in_array($theme['palette'] ?? null, SiteSections::PALETTES, true) ? $theme['palette'] : SiteSections::PALETTES[0],
            'font' => in_array($theme['font'] ?? null, SiteSections::FONTS, true) ? $theme['font'] : SiteSections::FONTS[0],
            'header_order_button' => (bool) ($theme['header_order_button'] ?? true),
            'footer_social' => (bool) ($theme['footer_social'] ?? true),
            'footer_text' => (string) ($theme['footer_text'] ?? ''),
        ];
    }

    /**
     * GET /site-builder/smart-draft?kind= - a standard page rebuilt from the
     * restaurant's data as it is now (dishes, photos, reviews, branches,
     * modules), for the editor to apply like a template. Home by default.
     */
    public function smartDraft(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['kind' => ['sometimes', Rule::in(SiteDraft::KINDS)]]);
        $page = (new SiteDraft($this->context->get()))->page($validated['kind'] ?? 'home')
            ?? throw ValidationException::withMessages(['kind' => ['Your package does not include this page.']]);

        return response()->json(['sections' => $page['sections'], 'meta_description' => $page['meta_description']]);
    }

    /** GET /site-builder/templates - layouts to start a page from. */
    public function templates(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $name = RestaurantBranding::current()['name'] ?? $this->context->get()?->name ?? 'Welcome';

        return response()->json(['templates' => SiteSections::templates($name, $this->modules())]);
    }

    /**
     * GET /site-pages - every page, with where the site stands against what
     * is live. The first visit creates the standard pages.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        if (! SitePage::exists()) {
            // The restaurant's first site, put together from its own data.
            $this->addStandardPages();
        }

        return response()->json($this->workspace());
    }

    /**
     * POST /site-pages - a new page: a standard one by `kind`, drafted from the
     * restaurant's data, or a blank one with the owner's title.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'kind' => ['nullable', Rule::in(array_diff(SiteDraft::KINDS, ['home']))],
            'title' => ['required_without:kind', 'nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        $standard = null;
        if (! empty($validated['kind'])) {
            if (SitePage::where('kind', $validated['kind'])->exists()) {
                throw ValidationException::withMessages(['kind' => ['Your site already has this page.']]);
            }
            $standard = (new SiteDraft($this->context->get()))->page($validated['kind'])
                ?? throw ValidationException::withMessages(['kind' => ['Your package does not include this page.']]);
        }

        $title = $validated['title'] ?? $standard['title'];
        $slug = $validated['slug'] ?? $standard['slug'] ?? (Str::slug($title) ?: 'page');
        $this->assertSlugFree($slug);

        $page = SitePage::create([
            'kind' => $standard['kind'] ?? null,
            'title' => $title,
            'slug' => $slug,
            'sort_order' => (int) SitePage::max('sort_order') + 1,
            'draft' => $standard['sections'] ?? [],
            'meta_description' => $standard['meta_description'] ?? null,
        ]);

        return response()->json(['page' => $this->present($page, $this->livePages())], 201);
    }

    /** POST /site-builder/standard-pages - adds every standard page the site lacks. */
    public function standardPages(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $added = $this->addStandardPages();

        return response()->json(['added' => $added] + $this->workspace());
    }

    /** PUT /site-builder/page-order - the order pages appear in the navigation. */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $pages = SitePage::all()->keyBy('id');
        $order = array_values(array_filter($validated['ids'], fn ($id) => $pages->has($id)));
        // Any page left out keeps its place after the ones given.
        $order = array_merge($order, $pages->keys()->diff($order)->values()->all());

        DB::transaction(function () use ($order, $pages) {
            foreach ($order as $i => $id) {
                $pages[$id]->update(['sort_order' => $i + 1]);
            }
        });

        return response()->json($this->workspace());
    }

    /** Saves the draft (and title/slug). Visitors see nothing until Publish website. */
    public function update(Request $request, SitePage $sitePage): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sections' => ['sometimes', 'array'],
            // What search engines and link previews show.
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'og_image' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^site/[A-Za-z0-9/_.-]+$#', 'not_regex:#\.\.#'],
        ]);

        if (isset($validated['slug']) && $validated['slug'] !== $sitePage->slug) {
            if ($sitePage->is_home) {
                throw ValidationException::withMessages(['slug' => ['The home page address cannot be changed.']]);
            }
            $this->assertSlugFree($validated['slug']);
            $sitePage->slug = $validated['slug'];
        }

        if (isset($validated['title'])) {
            $sitePage->title = $validated['title'];
        }

        if ($request->has('sections')) {
            $sitePage->draft = SiteSections::clean($request->input('sections'), $this->modules());
        }

        foreach (['meta_title', 'meta_description', 'og_image'] as $field) {
            if (array_key_exists($field, $validated)) {
                $sitePage->{$field} = $validated[$field] === '' ? null : $validated[$field];
            }
        }

        $sitePage->save();

        return response()->json(['page' => $this->present($sitePage, $this->livePages()), 'site' => $this->status()]);
    }

    /**
     * POST /site-builder/publish - Publish website: every page, the theme, the
     * header and footer, as a new numbered version that visitors now see.
     */
    public function publish(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        if (! SitePage::where('is_home', true)->exists()) {
            throw ValidationException::withMessages(['site' => ['The site needs a home page.']]);
        }

        // Re-checked: a draft saved before a component changed, or before the
        // package lost a module, must not publish something no longer allowed.
        $snapshot = $this->draftSnapshot(clean: true);

        $version = DB::transaction(function () use ($snapshot, $validated, $request) {
            $version = SiteVersion::create([
                'number' => (int) SiteVersion::lockForUpdate()->max('number') + 1,
                'snapshot' => $snapshot,
                'note' => isset($validated['note']) && trim($validated['note']) !== '' ? trim($validated['note']) : null,
                'published_by' => $request->user()->getKey(),
                'published_at' => now(),
            ]);
            $version->makeLive();

            return $version;
        });

        return response()->json(['version' => $this->presentVersion($version, $version->id)] + $this->workspace(), 201);
    }

    /** GET /site-builder/versions - every published version, newest first. */
    public function versions(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $liveId = SiteVersion::live()?->id;

        return response()->json([
            'versions' => SiteVersion::with('publisher:id,name')->orderByDesc('number')->limit(100)->get()
                ->map(fn (SiteVersion $v) => $this->presentVersion($v, $liveId)),
        ]);
    }

    /**
     * POST /site-builder/versions/{version}/make-live - puts the site back to
     * an earlier version (or forward again). The editor's drafts are untouched.
     */
    public function makeLive(Request $request, SiteVersion $siteVersion): JsonResponse
    {
        $this->authorizeManage($request);

        $siteVersion->makeLive();

        return response()->json(['version' => $this->presentVersion($siteVersion, $siteVersion->id)] + $this->workspace());
    }

    /**
     * POST /site-builder/versions/{version}/edit - replaces the editor's
     * drafts with a version: its pages, theme, header and footer. Nothing
     * changes for visitors until the next Publish website.
     */
    public function loadVersion(Request $request, SiteVersion $siteVersion): JsonResponse
    {
        $this->authorizeManage($request);

        $snapshot = $siteVersion->snapshot;

        DB::transaction(function () use ($snapshot) {
            SitePage::query()->delete();

            foreach (array_values($snapshot['pages'] ?? []) as $i => $page) {
                // Under the ids it had, so the editor can tell which pages
                // match the live site. They were this restaurant's pages and
                // ids are never reused, so nothing else can hold them.
                (new SitePage)->forceFill(['id' => $page['id'] ?? null])->fill([
                    'kind' => $page['kind'] ?? null,
                    'slug' => $page['slug'],
                    'title' => $page['title'],
                    'is_home' => (bool) ($page['is_home'] ?? false),
                    'sort_order' => $i + 1,
                    'draft' => $page['sections'] ?? [],
                    'meta_title' => $page['meta_title'] ?? null,
                    'meta_description' => $page['meta_description'] ?? null,
                    'og_image' => $page['og_image'] ?? null,
                ])->save();
            }

            $this->storeTheme(array_merge(self::defaultTheme(), $snapshot['theme'] ?? []));
        });

        return response()->json($this->workspace() + ['theme' => self::theme()]);
    }

    public function destroy(Request $request, SitePage $sitePage): JsonResponse
    {
        $this->authorizeManage($request);

        if ($sitePage->is_home) {
            throw ValidationException::withMessages(['page' => ['The home page cannot be deleted.']]);
        }

        // Only from the draft: the live site keeps it until the next Publish website.
        $sitePage->delete();

        return response()->json($this->workspace());
    }

    /** POST /site-builder/images - a photo for a Banner or Image section. */
    public function uploadImage(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $path = $request->file('image')->store('site/'.$this->context->id(), 'public');

        return response()->json(['path' => $path], 201);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }

    /** @return list<string> */
    private function modules(): array
    {
        return $this->context->get()?->modules() ?? [];
    }

    private function assertSlugFree(string $slug): void
    {
        if (SitePage::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => ['Another page already uses this address.']]);
        }
    }

    /** Adds every standard page the site does not have yet; returns their titles. */
    private function addStandardPages(): array
    {
        $existing = SitePage::pluck('kind')->filter()->all();
        $hasHome = SitePage::where('is_home', true)->exists();
        $slugs = SitePage::pluck('slug')->all();
        $order = (int) SitePage::max('sort_order');
        $added = [];

        foreach ((new SiteDraft($this->context->get()))->pages() as $page) {
            if (in_array($page['kind'], $existing, true) || ($page['is_home'] && $hasHome)) {
                continue;
            }

            // An owner's own page may already use the address.
            $slug = $page['slug'];
            for ($n = 2; in_array($slug, $slugs, true); $n++) {
                $slug = $page['slug'].'-'.$n;
            }
            $slugs[] = $slug;

            SitePage::create([
                'kind' => $page['kind'],
                'slug' => $slug,
                'title' => $page['title'],
                'is_home' => $page['is_home'],
                'sort_order' => ++$order,
                'draft' => $page['sections'],
                'meta_description' => $page['meta_description'],
            ]);
            $added[] = $page['title'];
        }

        return $added;
    }

    /** The pages, the live version, and which standard pages can still be added. */
    private function workspace(): array
    {
        $live = $this->livePages();
        $kinds = SitePage::pluck('kind')->filter()->all();

        return [
            'site_code' => $this->context->get()?->slug,
            'site' => $this->status(),
            'pages' => SitePage::orderBy('sort_order')->orderBy('id')->get()->map(fn (SitePage $page) => $this->present($page, $live)),
            'standard_pages' => collect((new SiteDraft($this->context->get()))->pages(withPhoto: false))
                ->reject(fn (array $p) => $p['is_home'])
                ->map(fn (array $p) => ['kind' => $p['kind'], 'title' => $p['title'], 'added' => in_array($p['kind'], $kinds, true)])
                ->values(),
        ];
    }

    /** Where the drafts stand against what visitors see. */
    private function status(): array
    {
        $live = SiteVersion::live();

        return [
            'live_version' => $live?->number,
            'latest_version' => SiteVersion::max('number'),
            'live_published_at' => $live?->published_at?->toIso8601String(),
            'has_unpublished_changes' => $live === null
                || self::comparable($this->draftSnapshot(clean: false)) !== self::comparable($live->snapshot),
        ];
    }

    /**
     * The whole draft site as a version would hold it.
     *
     * @param  bool  $clean  re-check every page against the components as they are now
     */
    private function draftSnapshot(bool $clean): array
    {
        $modules = $this->modules();

        return [
            'theme' => self::theme(),
            'pages' => SitePage::orderBy('sort_order')->orderBy('id')->get()->map(fn (SitePage $page) => [
                'id' => $page->id,
                'kind' => $page->kind,
                'slug' => $page->slug,
                'title' => $page->title,
                'is_home' => $page->is_home,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'og_image' => $page->og_image,
                'sections' => $clean ? SiteSections::clean($page->draft ?? [], $modules) : ($page->draft ?? []),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> the live version's pages by id */
    private function livePages(): array
    {
        return collect(SiteVersion::live()?->snapshot['pages'] ?? [])->keyBy('id')->all();
    }

    /**
     * A snapshot reduced to what visitors would see - key order and JSON
     * storage quirks aside - so two can be compared.
     */
    private static function comparable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::comparable(...), $value);
    }

    /** @return array<string, mixed> */
    private function present(SitePage $page, array $live): array
    {
        $current = [
            'kind' => $page->kind,
            'slug' => $page->slug,
            'title' => $page->title,
            'is_home' => $page->is_home,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'og_image' => $page->og_image,
            'sections' => $page->draft ?? [],
        ];
        $published = isset($live[$page->id]) ? array_intersect_key($live[$page->id], $current) : null;

        return [
            'id' => $page->id,
            'kind' => $page->kind,
            'title' => $page->title,
            'slug' => $page->slug,
            'is_home' => $page->is_home,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'og_image' => $page->og_image,
            'sections' => $page->draft ?? [],
            'is_live' => $published !== null,
            'has_unpublished_changes' => $published === null || self::comparable($published) !== self::comparable($current),
        ];
    }

    /** @return array<string, mixed> */
    private function presentVersion(SiteVersion $version, ?int $liveId): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'note' => $version->note,
            'published_at' => $version->published_at?->toIso8601String(),
            'published_by' => $version->publisher?->name,
            'pages' => collect($version->snapshot['pages'] ?? [])->pluck('title')->values(),
            'is_live' => $version->id === $liveId,
        ];
    }
}
