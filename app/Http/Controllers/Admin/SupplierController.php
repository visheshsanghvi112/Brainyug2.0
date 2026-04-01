<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialLedger;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\State;
use App\Models\District;
use App\Services\SupplierPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function __construct(
        private SupplierPaymentService $supplierPaymentService
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
        $invoiceSnapshots = $this->supplierPaymentService->invoiceSnapshots($supplier);

        $recentLedgers = $supplier->financialLedgers()
            ->withCount('supplierPaymentAllocations')
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
                'reversed_at',
                'reversal_reason',
                'reverses_financial_ledger_id',
            ]);

        $recentInvoices = $invoiceSnapshots->take(10)->values();
        $invoiceSettlementOptions = $invoiceSnapshots->values();
        $paymentAllocationMap = [];

        $paymentLedgerIds = $recentLedgers
            ->where('transaction_type', 'PAYMENT_MADE')
            ->pluck('id')
            ->all();

        if (!empty($paymentLedgerIds)) {
            $paymentAllocationMap = DB::table('supplier_payment_allocations as spa')
                ->join('purchase_invoices as pi', 'pi.id', '=', 'spa.purchase_invoice_id')
                ->whereIn('spa.financial_ledger_id', $paymentLedgerIds)
                ->selectRaw('
                    spa.financial_ledger_id,
                    spa.purchase_invoice_id,
                    pi.invoice_number,
                    pi.supplier_invoice_no,
                    COALESCE(SUM(spa.amount), 0) as allocated_amount
                ')
                ->groupBy('spa.financial_ledger_id', 'spa.purchase_invoice_id', 'pi.invoice_number', 'pi.supplier_invoice_no')
                ->get()
                ->filter(fn ($row) => abs((float) $row->allocated_amount) > 0.009)
                ->groupBy('financial_ledger_id')
                ->map(fn ($rows) => $rows
                    ->map(fn ($row) => [
                        'purchase_invoice_id' => (int) $row->purchase_invoice_id,
                        'invoice_number' => $row->invoice_number,
                        'supplier_invoice_no' => $row->supplier_invoice_no,
                        'allocated_amount' => round((float) $row->allocated_amount, 2),
                    ])
                    ->values()
                    ->all())
                ->all();
        }

        $recentReturns = PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->whereNull('reversed_at')
            ->latest('return_date')
            ->limit(8)
            ->get([
                'id',
                'return_number',
                'return_date',
                'status',
                'total_amount',
                'reason',
            ]);

        $grossPurchases = (float) $supplier->purchaseInvoices()->approved()->sum('total_amount');
        $grossReturns = (float) PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->whereNull('reversed_at')
            ->sum('total_amount');
        $paymentsMade = round((float) $invoiceSnapshots->sum('paid_amount'), 2);
        $overdueInvoices = $invoiceSnapshots->where('is_overdue', true)->count();
        $overdueExposure = round((float) $invoiceSnapshots->where('is_overdue', true)->sum('outstanding_amount'), 2);
        $openInvoiceExposure = round((float) $invoiceSnapshots->sum('outstanding_amount'), 2);
        $openInvoiceCount = $invoiceSnapshots->filter(fn (array $snapshot) => (float) $snapshot['outstanding_amount'] > 0)->count();

        return Inertia::render('Procurement/Suppliers/Show', [
            'supplier' => $supplier,
            'summary' => [
                'current_balance' => $latestBalance,
                'gross_purchases' => $grossPurchases,
                'gross_returns' => $grossReturns,
                'payments_made' => $paymentsMade,
                'overdue_invoices' => $overdueInvoices,
                'overdue_exposure' => $overdueExposure,
                'open_invoice_exposure' => $openInvoiceExposure,
                'open_invoice_count' => $openInvoiceCount,
            ],
            'recentLedgers' => $recentLedgers,
            'recentInvoices' => $recentInvoices,
            'invoiceSettlementOptions' => $invoiceSettlementOptions,
            'paymentAllocationMap' => $paymentAllocationMap,
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

        try {
            $this->supplierPaymentService->recordPayment($supplier, $request->user(), $validated);
        } catch (\DomainException $e) {
            return back()->withErrors([
                'amount' => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.suppliers.show', $supplier)
            ->with('success', 'Supplier payment recorded and auto-allocated against open purchase invoices.');
    }

    public function reversePayment(Request $request, Supplier $supplier, FinancialLedger $financialLedger)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'reversal_date' => 'required|date',
        ]);

        try {
            $this->supplierPaymentService->reversePayment($supplier, $financialLedger, $request->user(), $validated);
        } catch (\DomainException $e) {
            return back()->withErrors([
                'payment_reversal' => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.suppliers.show', $supplier)
            ->with('success', 'Supplier payment reversed and invoice settlement reopened.');
    }

    public function reallocatePayment(Request $request, Supplier $supplier, FinancialLedger $financialLedger)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'reallocation_date' => 'required|date',
            'allocations' => 'required|array|min:1',
            'allocations.*.purchase_invoice_id' => 'required|integer|exists:purchase_invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0',
        ]);

        try {
            $this->supplierPaymentService->reallocatePayment($supplier, $financialLedger, $request->user(), $validated);
        } catch (\DomainException $e) {
            return back()->withErrors([
                'payment_reallocation' => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.suppliers.show', $supplier)
            ->with('success', 'Supplier payment allocation corrected against the selected purchase invoices.');
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
