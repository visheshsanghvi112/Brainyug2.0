<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DistOrder;
use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceDraftService;
use App\Services\PurchaseInvoiceLifecycleService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private PurchaseInvoiceDraftService $purchaseInvoiceDraftService,
        private PurchaseInvoiceLifecycleService $purchaseInvoiceLifecycleService,
        private ReportExportService $reportExportService
    ) {}

    public function index(Request $request)
    {
        $invoices = PurchaseInvoice::with(['supplier', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->supplier_id, fn ($q, $supplierId) => $q->where('supplier_id', $supplierId))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $orderOpsMetrics = [
            'pending_orders' => DistOrder::where('status', 'pending')->count(),
            'pending_allocation' => DistOrder::where('status', 'accepted')->count(),
            'pending_dispatch' => DistOrder::where('status', 'allocated')->count(),
            'in_transit' => DistOrder::where('status', 'dispatched')->count(),
            'open_work' => DistOrder::whereIn('status', ['pending', 'accepted', 'allocated', 'dispatched'])->count(),
        ];

        $pendingOrderOps = DistOrder::with(['franchisee'])
            ->whereIn('status', ['pending', 'accepted', 'allocated', 'dispatched'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'allocated' THEN 2 WHEN 'dispatched' THEN 3 ELSE 4 END")
            ->latest('id')
            ->limit(8)
            ->get(['id', 'order_number', 'franchisee_id', 'status', 'total_amount', 'created_at', 'accepted_at', 'dispatched_at']);

        return Inertia::render('Procurement/PurchaseInvoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'orderOpsMetrics' => $orderOpsMetrics,
            'pendingOrderOps' => $pendingOrderOps,
        ]);
    }

    public function create()
    {
        return Inertia::render('Procurement/PurchaseInvoices/CreateEdit', [
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'gst_number']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku', 'mrp', 'hsn_id']),
            'hsn_codes' => HsnMaster::orderBy('hsn_code')->get(['id', 'hsn_code', 'cgst_percent', 'sgst_percent', 'igst_percent']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedInvoicePayload($request);

        if (!empty($validated['supplier_invoice_no'])) {
            $financialYear = PurchaseInvoice::currentFinancialYear();
            $duplicate = PurchaseInvoice::where('supplier_id', $validated['supplier_id'])
                ->where('supplier_invoice_no', $validated['supplier_invoice_no'])
                ->where('financial_year', $financialYear)
                ->whereNot('status', 'cancelled')
                ->first();

            if ($duplicate) {
                return back()->withErrors([
                    'supplier_invoice_no' => "Invoice #{$validated['supplier_invoice_no']} already exists for this supplier in FY {$financialYear}. (Ref: {$duplicate->invoice_number})",
                ])->withInput();
            }
        }

        $this->purchaseInvoiceDraftService->create($validated, $request->user()->id);

        return redirect()->route('admin.purchase-invoices.index')
            ->with('success', 'Purchase invoice created as draft.');
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load([
            'supplier',
            'items.product',
            'items.hsn',
            'createdBy',
            'approvedBy',
            'purchaseReturns.items',
            'purchaseReturns.createdBy',
        ]);

        $returnSummary = $this->buildReturnSummary($purchaseInvoice);
        $hasActiveReturns = ($returnSummary['draft_return_count'] + $returnSummary['approved_return_count']) > 0;

        return Inertia::render('Procurement/PurchaseInvoices/Show', [
            'invoice' => $purchaseInvoice,
            'returnSummary' => $returnSummary,
            'linkedReturns' => $purchaseInvoice->purchaseReturns
                ->sortByDesc(fn ($purchaseReturn) => sprintf(
                    '%02d|%s|%010d',
                    match ($purchaseReturn->status) {
                        'approved' => 3,
                        'draft' => 2,
                        default => 1,
                    },
                    optional($purchaseReturn->return_date)?->format('Ymd') ?? '00000000',
                    $purchaseReturn->id
                ))
                ->values()
                ->map(fn ($purchaseReturn) => [
                    'id' => $purchaseReturn->id,
                    'return_number' => $purchaseReturn->return_number,
                    'return_date' => optional($purchaseReturn->return_date)?->format('Y-m-d'),
                    'status' => $purchaseReturn->status,
                    'total_amount' => round((float) $purchaseReturn->total_amount, 2),
                    'created_by_name' => $purchaseReturn->createdBy?->name,
                ]),
            'actions' => [
                'can_edit' => $purchaseInvoice->canEdit(),
                'can_approve' => $purchaseInvoice->canApprove(),
                'can_cancel' => $purchaseInvoice->canCancel() && !$hasActiveReturns,
                'can_create_return' => $purchaseInvoice->status === 'approved' && !$returnSummary['is_fully_returned'],
            ],
        ]);
    }

    public function edit(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->isLegacy()) {
            return back()->with('error', 'Legacy historical invoices are read-only and cannot be edited.');
        }

        if (!$purchaseInvoice->canEdit()) {
            return back()->with('error', 'Only draft invoices can be edited.');
        }

        $purchaseInvoice->load(['items', 'supplier']);

        return Inertia::render('Procurement/PurchaseInvoices/CreateEdit', [
            'invoice' => $purchaseInvoice,
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'gst_number']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku', 'mrp', 'hsn_id']),
            'hsn_codes' => HsnMaster::orderBy('hsn_code')->get(['id', 'hsn_code', 'cgst_percent', 'sgst_percent', 'igst_percent']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
        ]);
    }

    public function update(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->isLegacy()) {
            return back()->with('error', 'Legacy historical invoices are read-only and cannot be updated.');
        }

        if (!$purchaseInvoice->canEdit()) {
            return back()->with('error', 'Only draft invoices can be updated.');
        }

        $validated = $this->validatedInvoicePayload($request);

        if (!empty($validated['supplier_invoice_no'])) {
            $duplicate = PurchaseInvoice::where('supplier_id', $validated['supplier_id'])
                ->where('supplier_invoice_no', $validated['supplier_invoice_no'])
                ->where('financial_year', $purchaseInvoice->financial_year)
                ->whereNot('status', 'cancelled')
                ->where('id', '!=', $purchaseInvoice->id)
                ->first();

            if ($duplicate) {
                return back()->withErrors([
                    'supplier_invoice_no' => "Invoice #{$validated['supplier_invoice_no']} already exists for this supplier in FY {$purchaseInvoice->financial_year}. (Ref: {$duplicate->invoice_number})",
                ])->withInput();
            }
        }

        try {
            $this->purchaseInvoiceDraftService->update($purchaseInvoice, $validated);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('admin.purchase-invoices.show', $purchaseInvoice)
            ->with('success', 'Purchase invoice updated successfully.');
    }

    /**
     * Approve a purchase invoice and post stock/payable side effects.
     */
    public function approve(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        try {
            $nearExpiryCount = $this->purchaseInvoiceLifecycleService->approve($purchaseInvoice, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $message = 'Invoice approved. Stock added to warehouse and supplier payable recorded.';
        if ($nearExpiryCount > 0) {
            $message .= ' Warning: ' . $nearExpiryCount . ' batch(es) expire within 90 days.';
        }

        return back()->with('success', $message);
    }

    /**
     * Cancel a purchase invoice.
     */
    public function cancel(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        try {
            $this->purchaseInvoiceLifecycleService->cancel($purchaseInvoice, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Invoice cancelled.');
    }

    /**
     * Export purchase invoices to CSV.
     */
    public function export(Request $request)
    {
        $query = PurchaseInvoice::with(['supplier', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->supplier_id, fn ($q, $supplierId) => $q->where('supplier_id', $supplierId))
            ->latest();

        $invoices = $query->get();
        $exportHeaders = [
            'System Inv No', 'Supplier Inv No', 'Date', 'Supplier', 'Status',
            'Total Amount', 'Tax Type', 'CGST', 'SGST', 'IGST', 'Created By',
        ];

        $rows = $invoices->map(fn (PurchaseInvoice $invoice) => [
            $invoice->invoice_number,
            $invoice->supplier_invoice_no,
            optional($invoice->invoice_date)?->format('Y-m-d'),
            $invoice->supplier->name ?? '',
            ucfirst($invoice->status),
            round((float) $invoice->total_amount, 2),
            strtoupper((string) $invoice->tax_type),
            round((float) $invoice->cgst_amount, 2),
            round((float) $invoice->sgst_amount, 2),
            round((float) $invoice->igst_amount, 2),
            $invoice->createdBy->name ?? '',
        ])->all();

        $summary = [
            'Invoices' => $invoices->count(),
            'Draft' => $invoices->where('status', 'draft')->count(),
            'Approved' => $invoices->where('status', 'approved')->count(),
            'Cancelled' => $invoices->where('status', 'cancelled')->count(),
            'Total Amount' => round((float) $invoices->sum('total_amount'), 2),
        ];

        $format = strtolower((string) $request->input('format', 'csv'));

        if ($format === 'excel') {
            return $this->reportExportService->downloadExcel(
                fileBase: 'purchase_invoices',
                sheetTitle: 'Purchase Invoices',
                headers: $exportHeaders,
                rows: $rows,
                meta: $summary,
            );
        }

        if ($format === 'pdf') {
            return $this->reportExportService->downloadPdf(
                fileBase: 'purchase_invoices',
                title: 'Purchase Invoices',
                headers: $exportHeaders,
                rows: $rows,
                meta: $summary,
            );
        }

        $filename = 'purchase_invoices_' . date('Y-m-d_H-i-s') . '.csv';

        $responseHeaders = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$filename",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows, $exportHeaders) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $exportHeaders);

            foreach ($rows as $row) {
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $responseHeaders);
    }

    /**
     * Print view for purchase invoice.
     */
    public function print(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load(['supplier', 'items.product', 'items.hsn', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseInvoices/Print', [
            'invoice' => $purchaseInvoice,
        ]);
    }

    private function validatedInvoicePayload(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_no' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'received_date' => 'nullable|date',
            'due_days' => 'nullable|integer|min:0|max:365',
            'transporter' => 'nullable|string|max:100',
            'lr_number' => 'nullable|string|max:50',
            'tax_type' => 'required|in:intra_state,inter_state',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.mfg_date' => 'nullable|date',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:20',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.gst_percent' => 'required|numeric|min:0',
            'items.*.hsn_id' => 'nullable|exists:hsn_masters,id',
        ]);
    }

    private function buildReturnSummary(PurchaseInvoice $purchaseInvoice): array
    {
        $purchasedQty = round((float) $purchaseInvoice->items->sum(fn ($item) => (float) $item->qty + (float) $item->free_qty), 2);

        $approvedReturns = $purchaseInvoice->purchaseReturns->where('status', 'approved');
        $draftReturns = $purchaseInvoice->purchaseReturns->where('status', 'draft');

        $approvedReturnedQty = round((float) $approvedReturns->flatMap->items->sum(fn ($item) => (float) $item->qty), 2);
        $draftReturnQty = round((float) $draftReturns->flatMap->items->sum(fn ($item) => (float) $item->qty), 2);
        $remainingReturnableQty = round(max(0, $purchasedQty - $approvedReturnedQty), 2);

        return [
            'purchased_qty' => $purchasedQty,
            'approved_returned_qty' => $approvedReturnedQty,
            'draft_return_qty' => $draftReturnQty,
            'remaining_returnable_qty' => $remainingReturnableQty,
            'approved_return_count' => $approvedReturns->count(),
            'draft_return_count' => $draftReturns->count(),
            'approved_return_total' => round((float) $approvedReturns->sum('total_amount'), 2),
            'draft_return_total' => round((float) $draftReturns->sum('total_amount'), 2),
            'is_fully_returned' => $purchaseInvoice->status === 'approved' && $remainingReturnableQty <= 0.0001,
        ];
    }
}
