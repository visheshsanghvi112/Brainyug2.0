<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Only franchisee-scoped users + admins
        $query = Customer::withCount('salesInvoices')
            ->withSum(['salesInvoices as total_spend' => fn($q) => $q->where('status', 'completed')], 'total_amount')
            ->latest();

        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
        } elseif ($user->hasRole('Super Admin')) {
            if ($request->filled('franchisee_id')) {
                $query->where('franchisee_id', $request->franchisee_id);
            }
        } else {
            abort(403);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('mobile', 'like', "%{$request->search}%");
            });
        }

        return Inertia::render('Customers/Index', [
            'customers' => $query->paginate(30)->withQueryString(),
            'filters'   => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->franchisee_id && !$user->hasRole('Super Admin')) {
            abort(403);
        }

        $validated = $request->validate([
            'name'    => 'required|string|max:150',
            'mobile'  => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        $franchiseeId = $user->franchisee_id;

        // Prevent duplicate mobile within the same franchisee
        $exists = Customer::where('franchisee_id', $franchiseeId)
            ->where('mobile', $validated['mobile'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['mobile' => 'A customer with this mobile number already exists.']);
        }

        Customer::create(array_merge($validated, ['franchisee_id' => $franchiseeId]));

        return back()->with('success', 'Customer added successfully.');
    }

    public function update(Request $request, Customer $customer)
    {
        $user = $request->user();
        if ($user->franchisee_id && $customer->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name'    => 'required|string|max:150',
            'mobile'  => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        $customer->update($validated);
        return back()->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer, Request $request)
    {
        $user = $request->user();
        if ($user->franchisee_id && $customer->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }
        $customer->delete();
        return back()->with('success', 'Customer removed.');
    }
}
