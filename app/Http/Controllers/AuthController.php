<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Billing\SubscriptionNotice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Email addresses are unique *per tenant*, not globally, so every lookup here
 * has to be tenant-qualified. ResolveTenant has already put the tenant in
 * context from the X-Tenant-ID header (the "restaurant code" on the login
 * form), and the BelongsToTenant global scope applies it to these queries - but
 * the validation rules below have to be scoped by hand, because `unique:users`
 * talks to the database directly and ignores Eloquent scopes.
 */
class AuthController extends Controller
{
    public function register(Request $request, TenantContext $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Scoped: two restaurants may each have a manager@ address.
                Rule::unique('users')->where('tenant_id', $tenant->id()),
            ],
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'user' => $user,
            'tenant' => $tenant->get(),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ], 201);
    }

    public function login(Request $request, TenantContext $tenant)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Scoped by BelongsToTenant to the tenant ResolveTenant established.
        // Without that scope this would return whichever tenant's user happens
        // to share the address.
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return response()->json([
            'user' => $user,
            'tenant' => $tenant->get(),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request, TenantContext $tenant)
    {
        $user = $request->user()->load('location');
        $user->all_permissions = $user->getAllPermissions()->pluck('name');

        // The frontend needs this to namespace its per-tenant local storage and
        // to keep sending the right X-Tenant-ID after a reload.
        $user->tenant = $tenant->get();
        $user->is_platform_admin = $user->isPlatformAdmin();

        // Billing state, so the admin can show a read-only banner on load
        // rather than waiting for the user to lose work on a refused save.
        $user->subscription = $tenant->get() !== null
            ? SubscriptionNotice::status($tenant->get())
            : null;

        return response()->json($user);
    }
}
