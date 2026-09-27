<?php

namespace App\Http\Controllers;

use App\Models\OneTimeLoginToken;
use App\Rules\PhoneNumber;
use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        if ($request->has('nopaginate')) {
            return response()->json(User::with(['location', 'roles'])->get());
        }
        return response()->json(User::with(['location', 'roles'])->paginate($perPage));
    }

    public function store(Request $request)
    {
        // Sign-in is on unless the form says otherwise. A no-login user - a
        // rider to assign deliveries to, a waiter credited with sales - needs
        // no password at all.
        $canLogin = $request->has('can_login') ? $request->boolean('can_login') : true;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', $this->tenantUnique('users')],
            'password' => [Rule::requiredIf($canLogin), 'nullable', 'string', 'min:8'],
            'location_id' => ['nullable', $this->tenantExists('locations')],
            'role' => ['nullable', 'string', $this->tenantExists('roles', 'name')],
            'phone' => ['nullable', 'string', 'max:20', PhoneNumber::mobile()],
            'image' => 'nullable|image|max:5120',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('users', 'public');
            $validated['image_url'] = $path;
        }

        $this->guardCanLogin($canLogin, $validated['role'] ?? null, null);

        // The column cannot be empty, so a no-login account gets a random
        // password nobody knows - there is nothing to sign in with.
        $validated['password'] = Hash::make($canLogin ? $validated['password'] : Str::random(48));
        $validated['can_login'] = $canLogin;

        $user = User::create($validated);
        
        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }
        
        return response()->json($user->load('roles'), 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load(['location', 'roles']));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->request->all();
        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email:rfc,strict', 'max:255', $this->tenantUnique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'location_id' => ['nullable', $this->tenantExists('locations')],
            'role' => ['nullable', 'string', $this->tenantExists('roles', 'name')],
            'phone' => ['nullable', 'string', 'max:20', PhoneNumber::mobile()],
            'image' => 'nullable|image|max:5120',
        ];

        $request->validate($rules);

        if (isset($validated['email']) && $validated['email'] !== $user->email
            && $user->hasRole('restaurant_admin')) {
            return response()->json([
                'message' => 'The owner account email cannot be changed.',
                'errors' => ['email' => ['The owner account email cannot be changed.']],
            ], 422);
        }

        $wasAllowed = $user->canLogIn();
        unset($validated['can_login']);

        if ($request->has('can_login')) {
            $canLogin = $request->boolean('can_login');
            $role = array_key_exists('role', $validated) ? $validated['role'] : $user->roles->first()?->name;

            $this->guardCanLogin($canLogin, $role, $user);

            // Turning sign-in on for an account that never had a usable
            // password: it needs one to sign in with.
            if ($canLogin && ! $wasAllowed && empty($validated['password'])) {
                throw ValidationException::withMessages([
                    'password' => ['Set a password to allow this user to sign in.'],
                ]);
            }

            $validated['can_login'] = $canLogin;
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('users', 'public');
            $validated['image_url'] = $path;
        }

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        
        if (isset($validated['role'])) {
            $user->syncRoles($validated['role'] ? [$validated['role']] : []);
        }

        // Switched off: end every session they already have, rather than
        // leaving them signed in until their token happens to expire.
        if ($wasAllowed && ! $user->canLogIn()) {
            $user->tokens()->delete();
        }

        return response()->json($user->load('roles'));
    }

    /**
     * Refuses a no-login setting that would lock the restaurant out.
     *
     * The owner must always be able to sign in - they are the only one who
     * could switch it back on - and nobody may switch off their own sign-in
     * from inside the session they are using.
     */
    private function guardCanLogin(bool $canLogin, ?string $role, ?User $user): void
    {
        if ($canLogin) {
            return;
        }

        if ($role === 'restaurant_admin' || $user?->hasRole('restaurant_admin')) {
            throw ValidationException::withMessages([
                'can_login' => ['The owner account must be able to sign in.'],
            ]);
        }

        if ($user !== null && $user->is(auth()->user())) {
            throw ValidationException::withMessages([
                'can_login' => ['You cannot switch off sign-in for your own account.'],
            ]);
        }
    }

    public function loginLink(Request $request, User $user)
    {
        if (! $request->user()->hasRole('restaurant_admin')) {
            abort(403, 'Only the restaurant admin can generate login links.');
        }

        if (! $user->canLogIn()) {
            return response()->json([
                'message' => 'This user is not allowed to sign in, so a login link cannot be made for them.',
            ], 422);
        }

        $token = OneTimeLoginToken::issueFor($user);
        $appUrl = rtrim((string) config('platform.app_url', config('app.url')), '/');

        return response()->json([
            'one_time_login_url' => $appUrl.'/login/one-time?token='.urlencode($token),
            'expires_in_hours' => min(
                (int) config('platform.login_link_ttl_hours', 24),
                OneTimeLoginToken::MAX_TTL_HOURS,
            ),
        ]);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }
}
