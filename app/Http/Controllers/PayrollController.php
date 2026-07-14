<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        if ($request->has('nopaginate')) {
            return response()->json(Payroll::with('user')->get());
        }
        return response()->json(Payroll::with('user')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000',
            'basic_salary' => 'required|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
            'overtime_pay' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:pending,paid',
        ]);

        $validated['net_pay'] = $validated['basic_salary'] 
            + ($validated['bonus'] ?? 0) 
            + ($validated['overtime_pay'] ?? 0) 
            - ($validated['deductions'] ?? 0);

        $payroll = Payroll::create($validated);
        return response()->json($payroll, 201);
    }

    public function show(Payroll $payroll)
    {
        return response()->json($payroll->load('user'));
    }

    public function update(Request $request, Payroll $payroll)
    {
        $validated = $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'month' => 'sometimes|integer|min:1|max:12',
            'year' => 'sometimes|integer|min:2000',
            'basic_salary' => 'sometimes|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
            'overtime_pay' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:pending,paid',
        ]);

        $basic = $validated['basic_salary'] ?? $payroll->basic_salary;
        $bonus = array_key_exists('bonus', $validated) ? $validated['bonus'] : $payroll->bonus;
        $overtime = array_key_exists('overtime_pay', $validated) ? $validated['overtime_pay'] : $payroll->overtime_pay;
        $deductions = array_key_exists('deductions', $validated) ? $validated['deductions'] : $payroll->deductions;

        $validated['net_pay'] = $basic + ($bonus ?? 0) + ($overtime ?? 0) - ($deductions ?? 0);

        $payroll->update($validated);
        return response()->json($payroll);
    }

    public function destroy(Payroll $payroll)
    {
        $payroll->delete();
        return response()->json(null, 204);
    }
}
