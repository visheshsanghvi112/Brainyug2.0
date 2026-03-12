<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Expense;
use App\Models\ExpenseCategory;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Admins see all Franchisee expenses + HO expenses
        // Franchisees see ONLY their own
        
        $query = Expense::with(['expenseCategory', 'user']);
        
        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
        }

        $expenses = $query->latest()->paginate(20);

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses
        ]);
    }

    public function create(Request $request)
    {
        $categories = ExpenseCategory::where('is_active', true)->get();
        return Inertia::render('Expenses/CreateEdit', [
            'categories' => $categories
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'expense_date' => 'required|date',
            'vendor_name' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'gst_amount' => 'required|numeric|min:0',
            'payment_mode' => 'required|string',
            'narration' => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['franchisee_id'] = $request->user()->franchisee_id;
        $validated['total_amount'] = $validated['amount'] + $validated['gst_amount'];
        $validated['voucher_number'] = 'EXP-' . date('YmdHis');

        Expense::create($validated);

        return redirect()->route('expenses.index')->with('success', 'Expense log saved.');
    }
}
