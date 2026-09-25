<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;

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
            return response()->json($query->get());
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->tenantUnique('roles')],
            'guard_name' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => $validated['guard_name'] ?? 'web',
            // Stamp the tenant explicitly rather than trusting the team resolver
            // to fill it, so a new role can never land global by accident.
            'tenant_id' => $this->tenantId(),
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

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
            'guard_name' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
        }
        if (isset($validated['guard_name'])) {
            $role->guard_name = $validated['guard_name'];
        }
        $role->save();

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        $this->assertOwned($role);

        $role->delete();
        return response()->json(null, 204);
    }
}
