<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        if ($request->has('nopaginate')) {
            return response()->json(Leave::with('user')->get());
        }
        return response()->json(Leave::with('user')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string',
            'status' => 'nullable|string|in:pending,approved,rejected',
        ]);

        $leave = Leave::create($validated);
        return response()->json($leave, 201);
    }

    public function show(Leave $leave)
    {
        return response()->json($leave->load('user'));
    }

    public function update(Request $request, Leave $leave)
    {
        $validated = $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'reason' => 'nullable|string',
            'status' => 'nullable|string|in:pending,approved,rejected',
        ]);

        $leave->update($validated);
        return response()->json($leave);
    }

    public function destroy(Leave $leave)
    {
        $leave->delete();
        return response()->json(null, 204);
    }
}
