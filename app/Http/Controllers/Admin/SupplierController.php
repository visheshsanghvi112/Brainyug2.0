<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialLedger;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\State;
use App\Models\District;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService
    ) {}

    public function index(Request $request)
    {
        $suppliers = Supplier::with(['state', 'district'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                       ->orWhere('code', 'like', "%{$search}%")
                       ->orWhere('gst_number', 'like', "%{$search}%")
                       ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->has('active'), fn($q) => $q->where('is_active', $request->boolean('active')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Procurement/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'active']),
        ]);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['state', 'district']);

        $latestBalance = $this->currentBalance($supplier);

        $recentLedgers = $supplier->financialLedgers()
            ->latest('transaction_date')
            ->latest('id')
            ->limit(12)
            ->get([
                'id',
                'transaction_date',
                'transaction_type',
                'voucher_no',
                'debit',
                'credit',
                'running_balance',
                'payment_mode',
                'narration',
            ]);

        $recentInvoices = $supplier->purchaseInvoices()
            ->approved()
            ->latest('invoice_date')
            ->limit(10)
            ->get([
                'id',
                'invoice_number',
                'supplier_invoice_no',
                'invoice_date',
                'due_days',
                'total_amount',
                'status',
            ])
            ->map(function ($invoice) {
                $dueDate = $invoice->invoice_date?->copy()->addDays((int) ($invoice->due_days ?? 0));

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'supplier_invoice_no' => $invoice->supplier_invoice_no,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'due_days' => (int) ($invoice->due_days ?? 0),
                    'due_date' => $dueDate?->toDateString(),
                    'total_amount' => (float) $invoice->total_amount,
                    'status' => $invoice->status,
                ];
            })
            ->values();

        $recentReturns = PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->latest('return_date')
            ->limit(8)
            ->get([
                'id',
                'return_number',
                'return_date',
                'total_amount',
                'reason',
            ]);

        $grossPurchases = (float) $supplier->purchaseInvoices()->approved()->sum('total_amount');
        $grossReturns = (float) PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->sum('total_amount');
        $paymentsMade = (float) $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_MADE')
            ->sum('debit');
        $overdueInvoices = $supplier->purchaseInvoices()
            ->approved()
            ->whereRaw('DATE_ADD(invoice_date, INTERVAL COALESCE(due_days, 0) DAY) < CURDATE()')
            ->count();
        $overdueExposure = (float) $supplier->purchaseInvoices()
            ->approved()
            ->whereRaw('DATE_ADD(invoice_date, INTERVAL COALESCE(due_days, 0) DAY) < CURDATE()')
            ->sum('total_amount');

        return Inertia::render('Procurement/Suppliers/Show', [
            'supplier' => $supplier,
            'summary' => [
                'current_balance' => $latestBalance,
                'gross_purchases' => $grossPurchases,
                'gross_returns' => $grossReturns,
                'payments_made' => $paymentsMade,
                'overdue_invoices' => $overdueInvoices,
                'overdue_exposure' => $overdueExposure,
            ],
            'recentLedgers' => $recentLedgers,
            'recentInvoices' => $recentInvoices,
            'recentReturns' => $recentReturns,
            'ledgerUrl' => route('ledger.index', ['type' => 'supplier', 'id' => $supplier->id]),
        ]);
    }

    public function create()
    {
        return Inertia::render('Procurement/Suppliers/CreateEdit', [
            'states' => State::orderBy('name')->get(['id', 'name']),
            'districts' => District::orderBy('name')->get(['id', 'name', 'state_id']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:suppliers,code',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'state_id' => 'nullable|exists:states,id',
            'district_id' => 'nullable|exists:districts,id',
            'pincode' => 'nullable|string|max:10',
            'gst_number' => 'nullable|string|max:20',
            'pan_number' => 'nullable|string|max:12',
            'dl_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_ifsc' => 'nullable|string|max:15',
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_days' => 'nullable|integer|min:0',
        ]);

        Supplier::create($validated);

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier created.');
    }

    public function edit(Supplier $supplier)
    {
        return Inertia::render('Procurement/Suppliers/CreateEdit', [
            'supplier' => $supplier,
            'states' => State::orderBy('name')->get(['id', 'name']),
            'districts' => District::orderBy('name')->get(['id', 'name', 'state_id']),
        ]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:suppliers,code,' . $supplier->id,
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'state_id' => 'nullable|exists:states,id',
            'district_id' => 'nullable|exists:districts,id',
            'pincode' => 'nullable|string|max:10',
            'gst_number' => 'nullable|string|max:20',
            'pan_number' => 'nullable|string|max:12',
            'dl_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_ifsc' => 'nullable|string|max:15',
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_days' => 'nullable|integer|min:0',
        ]);

        $supplier->update($validated);

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier updated.');
    }

    public function recordPayment(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_mode' => 'required|string|max:30',
            'narration' => 'nullable|string|max:500',
        ]);

        $currentBalance = $this->currentBalance($supplier);

        if ($currentBalance <= 0) {
            return back()->with('error', 'This supplier does not have any outstanding payable to settle.');
        }

        if ((float) $validated['amount'] > $currentBalance) {
            return back()->withErrors([
                'amount' => 'Payment cannot exceed the current outstanding supplier balance.',
            ]);
        }

        $amount = round((float) $validated['amount'], 2);

        $this->ledgerService->recordEntry(
            $supplier,
            'PAYMENT_MADE',
            debit: $amount,
            credit: 0,
            reference: null,
            paymentMode: strtolower($validated['payment_mode']),
            narration: $validated['narration'] ?: "Supplier payment recorded for {$supplier->name}",
            transactionDate: $validated['payment_date'],
        );

        return redirect()->route('admin.suppliers.show', $supplier)
            ->with('success', 'Supplier payment recorded in the financial ledger.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier deleted.');
    }

    private function currentBalance(Supplier $supplier): float
    {
        return (float) ($supplier->financialLedgers()->latest('id')->value('running_balance') ?? 0);
    }
}
