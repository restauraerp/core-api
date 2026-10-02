<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Models\WebsiteSetting;
use App\Support\Branding\RestaurantBranding;
use App\Support\Site\SiteDraft;
use App\Support\Site\SiteSections;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The site builder's API: the restaurant's pages, edited as drafts and
 * published when ready. Behind module:website and manage_website_content.
 *
 * A restaurant that has never opened the builder still has a site - the
 * public side serves a starter home page - and the first visit here turns
 * that starter into a real, editable page.
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

    /** PUT /site-builder/theme - the site's colour palette and type style. */
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

        $store = fn (string $key, string $value) => WebsiteSetting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);

        $store('site_palette', $validated['palette']);
        $store('site_font', $validated['font']);
        if (array_key_exists('header_order_button', $validated)) {
            $store('site_header_order', $validated['header_order_button'] ? '1' : '0');
        }
        if (array_key_exists('footer_social', $validated)) {
            $store('site_footer_social', $validated['footer_social'] ? '1' : '0');
        }
        if (array_key_exists('footer_text', $validated)) {
            $store('site_footer_text', trim((string) $validated['footer_text']));
        }

        return response()->json(['theme' => self::theme()]);
    }

    /**
     * The current restaurant's site-wide settings - theme, header and footer -
     * with defaults for any never set.
     *
     * @return array{palette: string, font: string, header_order_button: bool, footer_social: bool, footer_text: string}
     */
    public static function theme(): array
    {
        $settings = WebsiteSetting::whereIn('key', [
            'site_palette', 'site_font', 'site_header_order', 'site_footer_social', 'site_footer_text',
        ])->pluck('value', 'key');

        return [
            'palette' => in_array($settings['site_palette'] ?? null, SiteSections::PALETTES, true) ? $settings['site_palette'] : SiteSections::PALETTES[0],
            'font' => in_array($settings['site_font'] ?? null, SiteSections::FONTS, true) ? $settings['site_font'] : SiteSections::FONTS[0],
            'header_order_button' => ($settings['site_header_order'] ?? '1') !== '0',
            'footer_social' => ($settings['site_footer_social'] ?? '1') !== '0',
            'footer_text' => (string) ($settings['site_footer_text'] ?? ''),
        ];
    }

    /**
     * GET /site-builder/smart-draft - the page rebuilt from the restaurant's
     * data as it is now (dishes, photos, reviews, branches, modules), for the
     * editor to apply like a template.
     */
    public function smartDraft(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json((new SiteDraft($this->context->get()))->build());
    }

    /** GET /site-builder/templates - layouts to start a page from. */
    public function templates(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $name = RestaurantBranding::current()['name'] ?? $this->context->get()?->name ?? 'Welcome';

        return response()->json(['templates' => SiteSections::templates($name, $this->modules())]);
    }

    /** GET /site-pages - every page, creating the starter home on first use. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        if (! SitePage::exists()) {
            // The restaurant's first site, put together from its own data.
            $draft = (new SiteDraft($this->context->get()))->build();
            SitePage::create([
                'slug' => 'home',
                'title' => 'Home',
                'is_home' => true,
                'sort_order' => 1,
                'draft' => $draft['sections'],
                'meta_description' => $draft['meta_description'],
            ]);
        }

        return response()->json([
            'site_code' => $this->context->get()?->slug,
            'pages' => SitePage::orderBy('sort_order')->orderBy('id')->get()->map(fn (SitePage $page) => $this->present($page)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['title']) ?: 'page';
        $this->assertSlugFree($slug);

        $page = SitePage::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'sort_order' => (int) SitePage::max('sort_order') + 1,
            'draft' => [],
        ]);

        return response()->json(['page' => $this->present($page)], 201);
    }

    /** Saves the draft (and title/slug). Visitors see nothing until Publish. */
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

        return response()->json(['page' => $this->present($sitePage)]);
    }

    /** Makes the draft what visitors see. */
    public function publish(Request $request, SitePage $sitePage): JsonResponse
    {
        $this->authorizeManage($request);

        $sitePage->forceFill([
            // Re-checked: a draft saved before a component changed must not
            // publish something the builder no longer allows.
            'published' => SiteSections::clean($sitePage->draft ?? [], $this->modules()),
            'published_at' => now(),
        ])->save();

        return response()->json(['page' => $this->present($sitePage)]);
    }

    /** Takes a page off the site, keeping its draft. The home page stays. */
    public function unpublish(Request $request, SitePage $sitePage): JsonResponse
    {
        $this->authorizeManage($request);

        if ($sitePage->is_home) {
            throw ValidationException::withMessages(['page' => ['The home page cannot be taken off the site.']]);
        }

        $sitePage->forceFill(['published' => null, 'published_at' => null])->save();

        return response()->json(['page' => $this->present($sitePage)]);
    }

    public function destroy(Request $request, SitePage $sitePage): JsonResponse
    {
        $this->authorizeManage($request);

        if ($sitePage->is_home) {
            throw ValidationException::withMessages(['page' => ['The home page cannot be deleted.']]);
        }

        $sitePage->delete();

        return response()->json(null, 204);
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

    /** @return array<string, mixed> */
    private function present(SitePage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'is_home' => $page->is_home,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'og_image' => $page->og_image,
            'sections' => $page->draft ?? [],
            'is_published' => $page->published !== null,
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
            'published_at' => $page->published_at?->toIso8601String(),
        ];
    }
}
