<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Customer::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50|unique:customers,phone',
            'email' => 'nullable|email|max:255',
            'points_balance' => 'nullable|integer|min:0',
        ]);

        $customer = Customer::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'points_balance' => $validated['points_balance'] ?? 0,
        ]);

        return response()->json($customer, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        return response()->json($customer->load(['pointTransactions', 'orders']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:50|unique:customers,phone,' . $customer->id,
            'email' => 'nullable|email|max:255',
            'points_balance' => 'nullable|integer|min:0',
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(['message' => 'Customer profile deleted successfully.']);
    }

    /**
     * Display orders purchase history of the customer.
     */
    public function orders(Customer $customer)
    {
        $orders = $customer->orders()
            ->with(['items.product', 'payments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }
}
