<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        return response()->json(Payment::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', $this->tenantExists('orders')],
            'method' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:255',
            // Why this payment looks the way it does - a bKash transaction id,
            // a card's last four, which guest paid for a shared table.
            'note' => 'nullable|string|max:500',
        ]);

        $payment = Payment::create($validated);

        return response()->json($payment, 201);
    }

    public function show(Payment $payment)
    {
        return response()->json($payment);
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'order_id' => ['sometimes', 'required', 'integer', $this->tenantExists('orders')],
            'method' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:500',
        ]);

        $payment->update($validated);

        return response()->json($payment);
    }

    public function destroy(Payment $payment)
    {
        $payment->delete();

        return response()->json(null, 204);
    }
}
