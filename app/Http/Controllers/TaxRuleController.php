<?php

namespace App\Http\Controllers;

use App\Models\TaxRule;
use Illuminate\Http\Request;

/**
 * NOTE: store() and update() previously called `$request->validate([])` - an
 * empty rule set, which returns an empty array. Every submitted field was
 * therefore discarded, `update([])` changed nothing, and the endpoint still
 * answered 200 with the untouched record - so the UI looked like it had saved
 * when nothing had been written.
 */
class TaxRuleController extends Controller
{
    public function index()
    {
        return response()->json(TaxRule::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $taxRule = TaxRule::create($validated);

        return response()->json($taxRule, 201);
    }

    public function show(TaxRule $taxRule)
    {
        return response()->json($taxRule);
    }

    public function update(Request $request, TaxRule $taxRule)
    {
        $validated = $request->validate($this->rules($taxRule));

        $taxRule->update($validated);

        return response()->json($taxRule);
    }

    public function destroy(TaxRule $taxRule)
    {
        $taxRule->delete();

        return response()->json(null, 204);
    }

    /**
     * `percentage` is the actual column. The frontend used to post `rate`,
     * which matches nothing in the schema and was dropped without complaint.
     *
     * The column is decimal(5,2), so anything at or above 1000 would overflow
     * it - capped at 100 since a tax rate above that is a typo, not a rate.
     */
    private function rules(?TaxRule $taxRule = null): array
    {
        $required = $taxRule === null ? 'required' : 'sometimes';

        return [
            'name' => [
                $required, 'string', 'max:255',
                $this->tenantUnique('tax_rules', 'name')->ignore($taxRule?->id),
            ],
            'percentage' => [$required, 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
