<?php

namespace App\Http\Controllers;

use App\Models\QuotationItem;
use Illuminate\Http\Request;

class QuotationItemController extends Controller
{
    public function index()
    {
        return response()->json(QuotationItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'quotation_id' => ['required', 'integer', $this->tenantExists('quotations')],
            'product_id' => ['required', 'integer', $this->tenantExists('products')],
            'quantity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $quotationItem = QuotationItem::create($validated);

        return response()->json($quotationItem, 201);
    }

    public function show(QuotationItem $quotationItem)
    {
        return response()->json($quotationItem);
    }

    public function update(Request $request, QuotationItem $quotationItem)
    {
        $validated = $request->validate([
            'quotation_id' => ['sometimes', 'required', 'integer', $this->tenantExists('quotations')],
            'product_id' => ['sometimes', 'required', 'integer', $this->tenantExists('products')],
            'quantity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $quotationItem->update($validated);

        return response()->json($quotationItem);
    }

    public function destroy(QuotationItem $quotationItem)
    {
        $quotationItem->delete();

        return response()->json(null, 204);
    }
}
