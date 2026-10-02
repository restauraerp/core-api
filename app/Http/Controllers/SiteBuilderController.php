<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Support\Branding\RestaurantBranding;
use App\Support\Site\SiteSections;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        return response()->json(['components' => SiteSections::definitions()]);
    }

    /** GET /site-pages - every page, creating the starter home on first use. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        if (! SitePage::exists()) {
            $name = RestaurantBranding::current()['name'] ?? $this->context->get()?->name ?? 'Welcome';
            SitePage::create([
                'slug' => 'home',
                'title' => 'Home',
                'is_home' => true,
                'sort_order' => 1,
                'draft' => SiteSections::starterHome($name),
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
            $sitePage->draft = SiteSections::clean($request->input('sections'));
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
            'published' => SiteSections::clean($sitePage->draft ?? []),
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
            'sections' => $page->draft ?? [],
            'is_published' => $page->published !== null,
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
            'published_at' => $page->published_at?->toIso8601String(),
        ];
    }
}
