<?php

namespace App\Http\Controllers;

use App\Services\DeliveryPersonService;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Roles are per-tenant, but Spatie's teams feature only scopes permission
 * *checks* - `Role::all()` still returns every tenant's rows. Every query here
 * is therefore filtered to the current tenant by hand. Without it the roles
 * screen listed all sixty-odd roles across every restaurant, and editing one
 * failed with "name has already been taken": the uniqueness rule is scoped to
 * the tenant, so saving another tenant's `pos_manager` collided with this
 * tenant's own. The platform-level `super_admin` (tenant_id NULL) is excluded
 * by the same filter and is not a tenant's to see or edit.
 */
class RoleController extends Controller
{
    private function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }

    /** Scopes a query to the roles this tenant owns. */
    private function ownedByTenant($query)
    {
        return $query->where('tenant_id', $this->tenantId());
    }

    /** A role from another tenant (or the global super_admin) is not found here. */
    private function assertOwned(Role $role): void
    {
        if ($role->tenant_id !== $this->tenantId()) {
            abort(404);
        }
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $query = $this->ownedByTenant(Role::with('permissions'));

        if ($request->has('nopaginate')) {
            $roles = $query->get()->map(function ($role) {
                $role->requires_login = (bool) $role->requires_login;

                return $role;
            });

            return response()->json($roles);
        }

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(function ($role) {
            $role->requires_login = (bool) $role->requires_login;

            return $role;
        });

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->tenantUnique('roles')],
            // Whether people given this role sign in to the panel - the
            // default for new users; each user can still be switched.
            'requires_login' => 'sometimes|boolean',
            'guard_name' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => $validated['guard_name'] ?? 'web',
            'requires_login' => $request->has('requires_login') ? $request->boolean('requires_login') : true,
            // Stamp the tenant explicitly rather than trusting the team resolver
            // to fill it, so a new role can never land global by accident.
            'tenant_id' => $this->tenantId(),
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        DeliveryPersonService::flushForTenant($role->tenant_id);

        return response()->json($role->load('permissions'), 201);
    }

    public function show(Role $role)
    {
        $this->assertOwned($role);

        return response()->json($role->load('permissions'));
    }

    public function update(Request $request, Role $role)
    {
        $this->assertOwned($role);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', $this->tenantUnique('roles')->ignore($role->id)],
            'requires_login' => 'sometimes|boolean',
            'guard_name' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
        }
        if (isset($validated['guard_name'])) {
            $role->guard_name = $validated['guard_name'];
        }
        if ($request->has('requires_login')) {
            // The owner is the one account that can switch everything back on.
            if ($role->name === RoleDefinitions::RESTAURANT_ADMIN && ! $request->boolean('requires_login')) {
                throw ValidationException::withMessages([
                    'requires_login' => ['The owner role must be able to sign in.'],
                ]);
            }

            $role->requires_login = $request->boolean('requires_login');
        }
        $role->save();

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        DeliveryPersonService::flushForTenant($role->tenant_id);

        return response()->json($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        $this->assertOwned($role);

        $tenantId = $role->tenant_id;
        $role->delete();
        DeliveryPersonService::flushForTenant($tenantId);

        return response()->json(null, 204);
    }

    /**
     * Populates missing predefined roles for the current tenant (e.g. newly introduced
     * roles like 'waiter' added after the restaurant was originally created).
     */
    public function syncPredefined(Request $request)
    {
        $tenant = app(TenantContext::class)->get();
        if (! $tenant) {
            abort(400, 'Tenant context is missing.');
        }

        if (! $request->user()->hasRole(RoleDefinitions::RESTAURANT_ADMIN) && ! $request->user()->can('manage_roles_permissions')) {
            abort(403, 'Unauthorized to sync predefined roles.');
        }

        $added = app(TenantProvisioner::class)->addMissingRoles($tenant);

        if (! empty($added)) {
            DeliveryPersonService::flushForTenant($tenant->id);
        }

        return response()->json([
            'message' => empty($added) ? 'All predefined roles are already present.' : 'Predefined roles synchronized successfully.',
            'added' => $added,
        ]);
    }
}
