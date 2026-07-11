<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with(['location', 'roles'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'location_id' => 'nullable|exists:locations,id',
            'role' => 'nullable|string|exists:roles,name',
        ]);

        $validated['password'] = Hash::make($validated['password']);

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
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'location_id' => 'nullable|exists:locations,id',
            'role' => 'nullable|string|exists:roles,name',
        ];

        $request->validate($rules);

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        
        if (isset($validated['role'])) {
            $user->syncRoles($validated['role'] ? [$validated['role']] : []);
        }

        return response()->json($user->load('roles'));
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }
}
