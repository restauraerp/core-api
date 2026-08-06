<?php

namespace App\Support\Tenancy;

use App\Models\Location;
use App\Models\LoyaltySetting;
use App\Models\Page;
use App\Models\ProductCategory;
use App\Models\Tag;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Billing\Plans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Everything a brand new restaurant needs before its owner can log in and do
 * something useful.
 *
 * A tenant with no roles, no location and no categories is not a usable
 * account - the owner lands on an admin panel where every dropdown is empty and
 * they cannot even create a product. So provisioning is part of tenant
 * creation, not an optional follow-up step.
 *
 * Idempotent throughout: re-running against an existing tenant tops up whatever
 * is missing without duplicating what is there. That matters because the demo
 * box reseeds nightly and because a partially-failed provision must be safe to
 * retry.
 */
class TenantProvisioner
{
    public function __construct(private TenantContext $context) {}

    /**
     * Create a tenant and populate it.
     *
     * @param  array{name?: string, email?: string, password?: string, phone?: string}|null  $owner
     */
    public function create(array $attributes, ?array $owner = null): Tenant
    {
        return DB::transaction(function () use ($attributes, $owner) {
            $attributes['slug'] ??= $this->uniqueSlug($attributes['name']);
            $attributes['plan'] ??= Plans::default();
            $attributes['max_outlets'] ??= Plans::outletLimit($attributes['plan']);

            $tenant = Tenant::create($attributes);

            $this->provision($tenant, $owner);

            return $tenant;
        });
    }

    /**
     * Populate an existing tenant. Safe to re-run.
     *
     * @param  array{name?: string, email?: string, password?: string, phone?: string}|null  $owner
     */
    public function provision(Tenant $tenant, ?array $owner = null): Tenant
    {
        // Everything below writes through the tenant global scope and Spatie's
        // team id, both of which runFor() sets and restores.
        return $this->context->runFor($tenant, function () use ($tenant, $owner) {
            $this->createRoles($tenant);
            $this->createDefaultLocation($tenant);
            $this->createProductCategories();
            $this->createTags();
            $this->createWebsiteContent($tenant);
            $this->createLoyaltySettings();
            $this->createTaxRules();

            if ($owner !== null) {
                $this->createOwner($tenant, $owner);
            }

            return $tenant;
        });
    }

    /**
     * Per-tenant copies of every role template. Permissions themselves stay
     * global - see RoleDefinitions.
     */
    public function createRoles(?Tenant $tenant = null): void
    {
        $tenant ??= $this->context->get();

        // Every role is capped by what the tenant's tier includes, so a Starter
        // restaurant's owner simply has no CRM or HR permissions - and the
        // front, which renders its navigation from the permission list, hides
        // those modules without needing to know anything about plans.
        //
        // The API is guarded separately by the `module:` middleware: a role is
        // editable by the tenant, so permissions alone would be an entitlement
        // check the customer controls.
        $entitled = $tenant !== null && Plans::exists((string) $tenant->plan)
            ? Plans::permissions((string) $tenant->plan)
            : RoleDefinitions::permissions();

        $allPermissions = Permission::whereIn('name', $entitled)->get();

        foreach (RoleDefinitions::tenantRoles() as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $this->context->id(),
            ]);

            // null = every permission the tier includes, so the owner role
            // keeps picking up permissions added in later releases.
            $role->syncPermissions(
                $permissions === null
                    ? $allPermissions
                    : $allPermissions->whereIn('name', $permissions)
            );
        }
    }

    private function createOwner(Tenant $tenant, array $owner): User
    {
        $user = User::firstOrNew([
            'email' => $owner['email'],
            'tenant_id' => $tenant->getKey(),
        ]);

        $user->fill([
            'name' => $owner['name'] ?? $tenant->name.' Owner',
            'phone' => $owner['phone'] ?? null,
            'location_id' => Location::query()->value('id'),
        ]);

        // Only set a password on first creation - re-provisioning must not
        // reset a password the owner has since changed.
        if (! $user->exists) {
            $user->password = Hash::make($owner['password'] ?? Str::password(16));
            $user->email_verified_at = now();
        }

        $user->tenant_id = $tenant->getKey();
        $user->save();

        $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

        return $user;
    }

    private function createDefaultLocation(Tenant $tenant): void
    {
        if (Location::query()->exists()) {
            return;
        }

        Location::create([
            'name' => $tenant->name,
            'slug' => Str::slug($tenant->name),
            'type' => 'head_office',
            'phone' => $tenant->contact_phone,
            'email' => $tenant->contact_email,
            'is_active' => true,
        ]);
    }

    private function createProductCategories(): void
    {
        $categories = [
            'Appetizers', 'Main Courses', 'Desserts', 'Beverages', 'Salads',
            'Soups', 'Breakfast', 'Lunch Specials', 'Dinner', 'Sides',
            'Pizza', 'Pasta', 'Grill', 'Seafood', 'Kids Menu',
        ];

        foreach ($categories as $category) {
            ProductCategory::firstOrCreate(
                ['slug' => Str::slug($category)],
                ['name' => $category, 'is_active' => true],
            );
        }
    }

    private function createTags(): void
    {
        $tags = [
            'Spicy', 'Vegan', 'Vegetarian', 'Gluten-Free', 'Bestseller',
            'New', 'Halal', 'Dairy-Free', 'Nut-Free', "Chef's Special",
            'Organic', 'Low Carb', 'Healthy',
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(['slug' => Str::slug($tag)], ['name' => $tag]);
        }
    }

    /**
     * Neutral starter content keyed off the tenant's own name - deliberately
     * not the demo restaurant's copy, which would ship "Bangla Bistro" branding
     * to every new customer.
     */
    private function createWebsiteContent(Tenant $tenant): void
    {
        $settings = [
            ['key' => 'site_name', 'value' => $tenant->name, 'type' => 'string'],
            ['key' => 'tagline', 'value' => '', 'type' => 'string'],
            ['key' => 'logo_url', 'value' => '', 'type' => 'string'],
            ['key' => 'favicon_url', 'value' => '', 'type' => 'string'],
            ['key' => 'cover_image_url', 'value' => '', 'type' => 'string'],
            ['key' => 'contact_email', 'value' => (string) $tenant->contact_email, 'type' => 'string'],
            ['key' => 'contact_phone', 'value' => (string) $tenant->contact_phone, 'type' => 'string'],
            ['key' => 'whatsapp_number', 'value' => '', 'type' => 'string'],
            ['key' => 'address', 'value' => '', 'type' => 'string'],
            ['key' => 'google_maps_embed', 'value' => '', 'type' => 'string'],
            ['key' => 'opening_hours', 'value' => json_encode([
                'Monday' => '', 'Tuesday' => '', 'Wednesday' => '', 'Thursday' => '',
                'Friday' => '', 'Saturday' => '', 'Sunday' => '',
            ]), 'type' => 'json'],
            ['key' => 'meta_title', 'value' => $tenant->name, 'type' => 'string'],
            ['key' => 'meta_description', 'value' => '', 'type' => 'string'],
            ['key' => 'meta_keywords', 'value' => '', 'type' => 'string'],
            ['key' => 'cuisine_type', 'value' => '', 'type' => 'string'],
            ['key' => 'seating_capacity', 'value' => '', 'type' => 'string'],
            ['key' => 'founded_year', 'value' => '', 'type' => 'string'],
            ['key' => 'reservation_enabled', 'value' => 'true', 'type' => 'boolean'],
            ['key' => 'currency_symbol', 'value' => '৳', 'type' => 'string'],
        ];

        foreach ($settings as $setting) {
            WebsiteSetting::firstOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']],
            );
        }

        $pages = [
            ['slug' => 'about', 'title' => 'About '.$tenant->name],
            ['slug' => 'privacy-policy', 'title' => 'Privacy Policy'],
            ['slug' => 'terms', 'title' => 'Terms & Conditions'],
        ];

        foreach ($pages as $page) {
            Page::firstOrCreate(
                ['slug' => $page['slug']],
                ['title' => $page['title'], 'content' => '', 'meta_title' => $page['title']],
            );
        }
    }

    private function createLoyaltySettings(): void
    {
        if (LoyaltySetting::query()->exists()) {
            return;
        }

        LoyaltySetting::create([
            'points_per_amount' => 1,
            'cash_per_point' => 1,
            'tier_thresholds' => ['Bronze' => 0, 'Silver' => 500, 'Gold' => 2000, 'Platinum' => 5000],
        ]);
    }

    /**
     * Created inactive on purpose. Restaurant VAT in Bangladesh varies by
     * category (commonly 5% or 10%), so guessing and enabling it would quietly
     * put a wrong tax on every bill. The owner enables it once it is right.
     */
    private function createTaxRules(): void
    {
        TaxRule::firstOrCreate(
            ['name' => 'VAT'],
            ['percentage' => 5, 'is_active' => false],
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
