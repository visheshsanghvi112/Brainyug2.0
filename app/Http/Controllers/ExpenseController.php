<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Carbon;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Expense::with(['expenseCategory', 'user']);

        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('voucher_number', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhere('narration', 'like', "%{$search}%")
                    ->orWhereHas('expenseCategory', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('expense_category_id')) {
            $query->where('expense_category_id', (int) $request->input('expense_category_id'));
        }

        if ($request->filled('payment_mode')) {
            $query->where('payment_mode', (string) $request->input('payment_mode'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('has_tds')) {
            $hasTds = (string) $request->input('has_tds');
            if ($hasTds === 'yes') {
                $query->where('is_tds_applicable', true);
            }
            if ($hasTds === 'no') {
                $query->where('is_tds_applicable', false);
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('expense_date', '>=', (string) $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('expense_date', '<=', (string) $request->input('to_date'));
        }

        $expenses = $query->latest()->paginate(20);

        $summary = [
            'count' => (clone $query)->count(),
            'gross' => round((float) (clone $query)->sum('total_amount'), 2),
            'tds' => round((float) (clone $query)->sum('tds_amount'), 2),
            'net' => round((float) (clone $query)->sum('net_amount'), 2),
        ];

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'summary' => $summary,
            'filters' => $request->only([
                'search',
                'expense_category_id',
                'payment_mode',
                'status',
                'has_tds',
                'from_date',
                'to_date',
            ]),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'paymentModes' => ['cash', 'upi', 'bank', 'card'],
            'statuses' => ['pending', 'approved', 'rejected'],
        ]);
    }

    public function create(Request $request)
    {
        $categories = ExpenseCategory::where('is_active', true)->get();
        return Inertia::render('Expenses/CreateEdit', [
            'categories' => $categories,
            'expense' => null,
        ]);
    }

    public function edit(Request $request, Expense $expense)
    {
        $this->authorizeExpenseAccess($request->user(), $expense);

        return Inertia::render('Expenses/CreateEdit', [
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'expense' => $expense,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);

        $validated['user_id'] = $request->user()->id;
        $validated['franchisee_id'] = $request->user()->franchisee_id;
        $validated['voucher_number'] = 'EXP-' . Carbon::now()->format('YmdHis');

        $totals = $this->derivedAmounts($validated);
        $validated = array_merge($validated, $totals);

        Expense::create($validated);

        return redirect()->route('expenses.index')->with('success', 'Expense log saved.');
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorizeExpenseAccess($request->user(), $expense);

        $validated = $this->validatedPayload($request);
        $totals = $this->derivedAmounts($validated);

        $expense->update(array_merge($validated, $totals));

        return redirect()->route('expenses.index')->with('success', 'Expense updated successfully.');
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'expense_date' => 'required|date',
            'vendor_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'gst_amount' => 'required|numeric|min:0',
            'payment_mode' => 'required|in:cash,upi,bank,card',
            'narration' => 'nullable|string|max:2000',
            'status' => 'nullable|in:pending,approved,rejected',
            'is_tds_applicable' => 'nullable|boolean',
            'tds_percent' => 'nullable|numeric|min:0|max:100',
        ]);
    }

    private function derivedAmounts(array $validated): array
    {
        $amount = round((float) $validated['amount'], 2);
        $gstAmount = round((float) $validated['gst_amount'], 2);
        $total = round($amount + $gstAmount, 2);

        $isTdsApplicable = (bool) ($validated['is_tds_applicable'] ?? false);
        $tdsPercent = $isTdsApplicable ? round((float) ($validated['tds_percent'] ?? 0), 2) : 0;
        $tdsAmount = $isTdsApplicable ? round($total * ($tdsPercent / 100), 2) : 0;
        $netAmount = round(max(0, $total - $tdsAmount), 2);

        return [
            'status' => $validated['status'] ?? 'approved',
            'is_tds_applicable' => $isTdsApplicable,
            'tds_percent' => $tdsPercent,
            'tds_amount' => $tdsAmount,
            'total_amount' => $total,
            'net_amount' => $netAmount,
        ];
    }

    private function authorizeExpenseAccess($user, Expense $expense): void
    {
        if ($user->franchisee_id && (int) $user->franchisee_id !== (int) $expense->franchisee_id) {
            abort(403, 'You can only modify expenses from your own franchisee scope.');
        }
    }
}
