<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index()
    {
        return response()->json(SupportTicket::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $this->tenantExists('customers')],
            'subject' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $supportTicket = SupportTicket::create($validated);

        return response()->json($supportTicket, 201);
    }

    public function show(SupportTicket $supportTicket)
    {
        return response()->json($supportTicket);
    }

    public function update(Request $request, SupportTicket $supportTicket)
    {
        $validated = $request->validate([
            'customer_id' => ['sometimes', 'required', 'integer', $this->tenantExists('customers')],
            'subject' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $supportTicket->update($validated);

        return response()->json($supportTicket);
    }

    public function destroy(SupportTicket $supportTicket)
    {
        $supportTicket->delete();

        return response()->json(null, 204);
    }
}
