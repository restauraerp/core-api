<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index()
    {
        return response()->json(Organization::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:organizations,name',
        ]);

        $org = Organization::create($validated);
        return response()->json($org, 201);
    }
}
