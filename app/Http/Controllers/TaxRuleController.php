<?php

namespace App\Http\Controllers;

use App\Models\TaxRule;
use Illuminate\Http\Request;

class TaxRuleController extends Controller
{
    public function index()
    {
        return response()->json(TaxRule::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $taxRule = TaxRule::create($validated);
        return response()->json($taxRule, 201);
    }

    public function show(TaxRule $taxRule)
    {
        return response()->json($taxRule);
    }

    public function update(Request $request, TaxRule $taxRule)
    {
        $validated = $request->validate([
            
        ]);

        $taxRule->update($validated);
        return response()->json($taxRule);
    }

    public function destroy(TaxRule $taxRule)
    {
        $taxRule->delete();
        return response()->json(null, 204);
    }
}