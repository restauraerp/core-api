<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tenant;
use App\Support\Billing\Modules;
use App\Support\Billing\Plans;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custom packages, managed from the website's admin panel.
 *
 * The default tiers are listed alongside so the panel can show what it is
 * copying from, but they stay read-only here: they live in config/plans.php
 * and are what the pricing page sells.
 */
class PackageController extends Controller
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $defaults = collect(Plans::tiers())->map(fn (string $tier) => [
            'tier' => $tier,
            'name' => Plans::label($tier),
            'description' => Plans::tier($tier)['description'] ?? null,
            'modules' => Plans::modules($tier),
            'outlets' => Plans::outletLimit($tier),
        ])->values();

        $custom = Package::with(['tenants' => fn ($q) => $q->select('id', 'package_id', 'name', 'slug')])
            ->orderBy('name')
            ->get()
            ->map(fn (Package $package) => $this->present($package));

        return response()->json([
            'modules' => collect(Modules::ALL)->map(fn (string $module) => [
                'key' => $module,
                'name' => Modules::label($module),
                'core' => in_array($module, Modules::CORE, true),
            ])->values(),
            'defaults' => $defaults,
            'packages' => $custom,
        ]);
    }

    public function show(Package $package): JsonResponse
    {
        return response()->json(['package' => $this->present($package->load('tenants'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $package = Package::create($this->validated($request));

        return response()->json(['package' => $this->present($package->load('tenants'))], 201);
    }

    /**
     * Changing a package changes what every restaurant on it can use, so their
     * roles are re-synced here rather than waiting for somebody to notice.
     */
    public function update(Request $request, Package $package): JsonResponse
    {
        $package->update($this->validated($request));

        foreach ($package->tenants()->get() as $tenant) {
            $tenant->setRelation('package', $package);
            $this->context->runFor($tenant, fn () => $this->provisioner->createRoles($tenant));
        }

        return response()->json(['package' => $this->present($package->load('tenants'))]);
    }

    /** Refused while any restaurant is on it - move them off first. */
    public function destroy(Package $package): JsonResponse
    {
        $inUse = $package->tenants()->withTrashed()->count();

        if ($inUse > 0) {
            return response()->json([
                'message' => "This package is assigned to {$inUse} restaurant(s). Move them to another package first.",
                'error' => 'package_in_use',
            ], 409);
        }

        $package->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'based_on' => ['nullable', 'string', Rule::in(Plans::tiers())],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(Modules::ALL)],
            'outlets' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'modules.required' => 'Choose at least one module.',
            'modules.min' => 'Choose at least one module.',
        ]);

        // Canonical order, no duplicates.
        $validated['modules'] = array_values(array_intersect(Modules::ALL, $validated['modules']));

        return $validated;
    }

    /** @return array<string, mixed> */
    private function present(Package $package): array
    {
        return [
            'id' => $package->getKey(),
            'name' => $package->name,
            'description' => $package->description,
            'based_on' => $package->based_on,
            'modules' => $package->moduleList(),
            'outlets' => $package->outlets,
            'tenants' => $package->relationLoaded('tenants')
                ? $package->tenants->map(fn (Tenant $t) => ['name' => $t->name, 'restaurant_code' => $t->slug])->values()
                : [],
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }
}
