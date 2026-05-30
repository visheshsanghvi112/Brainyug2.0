<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DistOrder;
use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPaymentAllocation;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceDraftService;
use App\Services\PurchaseInvoiceLifecycleService;
use App\Services\ReportExportService;
use Illuminate\Support\Facades\DB;
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
        $financialYear = PurchaseInvoice::financialYearForDate($validated['invoice_date']);

        if (!empty($validated['supplier_invoice_no'])) {
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

    /**
     * Legacy parity helper: bulk import draft purchase invoices from CSV.
     *
     * Expected header columns (case-insensitive):
     * supplier_code,supplier_invoice_no,invoice_date,tax_type,product_sku,batch_no,qty,mrp,rate,gst_percent
     * Optional: received_date,due_days,transporter,lr_number,notes,free_qty,unit,discount_percent,expiry_date,mfg_date,hsn_code
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors([
                'file' => 'Unable to read uploaded CSV file.',
            ]);
        }

        $headers = fgetcsv($handle);
        if (!$headers || count($headers) === 0) {
            fclose($handle);
            return back()->withErrors([
                'file' => 'CSV file is empty or missing header row.',
            ]);
        }

        $normalizedHeaders = array_map(function ($value) {
            return strtolower(trim((string) $value));
        }, $headers);

        $required = ['supplier_code', 'supplier_invoice_no', 'invoice_date', 'tax_type', 'product_sku', 'batch_no', 'qty', 'mrp', 'rate', 'gst_percent'];
        $missing = array_values(array_diff($required, $normalizedHeaders));

        if (!empty($missing)) {
            fclose($handle);
            return back()->withErrors([
                'file' => 'CSV header missing required columns: ' . implode(', ', $missing),
            ]);
        }

        $supplierMap = Supplier::query()->get(['id', 'code'])->keyBy(fn ($supplier) => strtoupper((string) $supplier->code));
        $productMap = Product::query()->where('is_active', true)->get(['id', 'sku', 'hsn_id'])->keyBy(fn ($product) => strtoupper((string) $product->sku));
        $hsnMap = HsnMaster::query()->get(['id', 'hsn_code'])->keyBy(fn ($hsn) => strtoupper((string) $hsn->hsn_code));

        $groups = [];
        $line = 1;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $payload = [];
            foreach ($normalizedHeaders as $index => $header) {
                $payload[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
            }

            $supplierCode = strtoupper((string) ($payload['supplier_code'] ?? ''));
            $supplier = $supplierMap->get($supplierCode);
            if (!$supplier) {
                $errors[] = "Row {$line}: unknown supplier_code '{$payload['supplier_code']}'.";
                continue;
            }

            $sku = strtoupper((string) ($payload['product_sku'] ?? ''));
            $product = $productMap->get($sku);
            if (!$product) {
                $errors[] = "Row {$line}: unknown product_sku '{$payload['product_sku']}'.";
                continue;
            }

            $taxType = (string) ($payload['tax_type'] ?? '');
            if (!in_array($taxType, ['intra_state', 'inter_state'], true)) {
                $errors[] = "Row {$line}: tax_type must be intra_state or inter_state.";
                continue;
            }

            $invoiceDate = (string) ($payload['invoice_date'] ?? '');
            if ($invoiceDate === '') {
                $errors[] = "Row {$line}: invoice_date is required.";
                continue;
            }

            $groupKey = implode('|', [
                $supplier->id,
                (string) ($payload['supplier_invoice_no'] ?? ''),
                $invoiceDate,
                $taxType,
            ]);

            $hsnId = null;
            if (!empty($payload['hsn_code'])) {
                $hsn = $hsnMap->get(strtoupper((string) $payload['hsn_code']));
                if (!$hsn) {
                    $errors[] = "Row {$line}: unknown hsn_code '{$payload['hsn_code']}'.";
                    continue;
                }
                $hsnId = $hsn->id;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'supplier_id' => $supplier->id,
                    'supplier_invoice_no' => $payload['supplier_invoice_no'] ?: null,
                    'invoice_date' => $invoiceDate,
                    'received_date' => $payload['received_date'] ?: null,
                    'due_days' => is_numeric($payload['due_days'] ?? null) ? (int) $payload['due_days'] : 0,
                    'transporter' => $payload['transporter'] ?: null,
                    'lr_number' => $payload['lr_number'] ?: null,
                    'tax_type' => $taxType,
                    'notes' => $payload['notes'] ?: null,
                    'items' => [],
                ];
            }

            $groups[$groupKey]['items'][] = [
                'product_id' => $product->id,
                'batch_no' => (string) ($payload['batch_no'] ?? ''),
                'expiry_date' => $payload['expiry_date'] ?: null,
                'mfg_date' => $payload['mfg_date'] ?: null,
                'qty' => (float) ($payload['qty'] ?? 0),
                'free_qty' => (float) ($payload['free_qty'] ?? 0),
                'unit' => $payload['unit'] ?: null,
                'mrp' => (float) ($payload['mrp'] ?? 0),
                'rate' => (float) ($payload['rate'] ?? 0),
                'discount_percent' => (float) ($payload['discount_percent'] ?? 0),
                'gst_percent' => (float) ($payload['gst_percent'] ?? 0),
                'hsn_id' => $hsnId ?: $product->hsn_id,
            ];
        }

        fclose($handle);

        if (!empty($errors)) {
            return back()->withErrors([
                'file' => implode(' ', array_slice($errors, 0, 10)),
            ]);
        }

        if (empty($groups)) {
            return back()->withErrors([
                'file' => 'No valid rows found in CSV.',
            ]);
        }

        $created = 0;
        $duplicatesSkipped = 0;

        DB::transaction(function () use (&$created, &$duplicatesSkipped, $groups, $request) {
            foreach ($groups as $payload) {
                $fy = PurchaseInvoice::financialYearForDate($payload['invoice_date']);

                if (!empty($payload['supplier_invoice_no'])) {
                    $duplicate = PurchaseInvoice::query()
                        ->where('supplier_id', $payload['supplier_id'])
                        ->where('supplier_invoice_no', $payload['supplier_invoice_no'])
                        ->where('financial_year', $fy)
                        ->whereNot('status', 'cancelled')
                        ->exists();

                    if ($duplicate) {
                        $duplicatesSkipped++;
                        continue;
                    }
                }

                $this->purchaseInvoiceDraftService->create($payload, $request->user()->id);
                $created++;
            }
        });

        $statusKey = $created > 0 ? 'success' : 'error';
        $message = $created > 0
            ? "CSV imported successfully. {$created} draft invoice(s) created."
            : 'CSV processed, but no new invoices were created.';

        if ($duplicatesSkipped > 0) {
            $message .= " {$duplicatesSkipped} duplicate invoice group(s) skipped.";
        }

        return redirect()->route('admin.purchase-invoices.index')->with($statusKey, $message);
    }

    public function importTemplate()
    {
        $headers = [
            'supplier_code',
            'supplier_invoice_no',
            'invoice_date',
            'tax_type',
            'product_sku',
            'batch_no',
            'qty',
            'mrp',
            'rate',
            'gst_percent',
            'received_date',
            'due_days',
            'transporter',
            'lr_number',
            'notes',
            'free_qty',
            'unit',
            'discount_percent',
            'expiry_date',
            'mfg_date',
            'hsn_code',
        ];

        $sampleRows = [
            [
                'SUP001',
                'INV-2026-001',
                now()->toDateString(),
                'intra_state',
                'SKU-001',
                'BATCH-2401',
                '100',
                '12.50',
                '10.00',
                '12',
                now()->toDateString(),
                '30',
                'Self',
                'LR-001',
                'Initial stock intake',
                '5',
                'BOX',
                '0',
                now()->addMonths(18)->toDateString(),
                now()->subMonths(2)->toDateString(),
                '30049099',
            ],
        ];

        return response()->streamDownload(function () use ($headers, $sampleRows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);

            foreach ($sampleRows as $row) {
                fputcsv($file, $row);
            }

            fclose($file);
        }, 'purchase_invoice_import_template.csv', [
            'Content-Type' => 'text/csv',
        ]);
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
            'purchaseReturns.reversedBy',
        ]);

        $returnSummary = $this->buildReturnSummary($purchaseInvoice);
        $hasActiveReturns = ($returnSummary['draft_return_count'] + $returnSummary['approved_return_count']) > 0;

        $paymentAllocations = SupplierPaymentAllocation::query()
            ->with(['financialLedger:id,voucher_no,transaction_date,transaction_type,payment_mode,narration'])
            ->where('purchase_invoice_id', $purchaseInvoice->id)
            ->orderByDesc('id')
            ->get();

        $paidAmount = round((float) $paymentAllocations->sum('amount'), 2);
        $netPayable = round(max(0, (float) $purchaseInvoice->total_amount - (float) ($returnSummary['approved_return_total'] ?? 0)), 2);
        $outstanding = round(max(0, $netPayable - $paidAmount), 2);
        $dueDate = $purchaseInvoice->invoice_date
            ? $purchaseInvoice->invoice_date->copy()->addDays((int) ($purchaseInvoice->due_days ?? 0))
            : null;
        $isOverdue = $dueDate !== null && $outstanding > 0 && $dueDate->lt(now()->startOfDay());

        return Inertia::render('Procurement/PurchaseInvoices/Show', [
            'invoice' => $purchaseInvoice,
            'returnSummary' => $returnSummary,
            'paymentSummary' => [
                'net_payable' => $netPayable,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstanding,
                'is_overdue' => $isOverdue,
                'due_date' => optional($dueDate)?->format('Y-m-d'),
                'allocation_count' => $paymentAllocations->count(),
            ],
            'paymentAllocations' => $paymentAllocations->map(function ($allocation) {
                return [
                    'id' => $allocation->id,
                    'amount' => round((float) $allocation->amount, 2),
                    'allocation_date' => optional($allocation->allocation_date)?->format('Y-m-d'),
                    'voucher_no' => $allocation->financialLedger?->voucher_no,
                    'transaction_date' => optional($allocation->financialLedger?->transaction_date)?->format('Y-m-d'),
                    'transaction_type' => $allocation->financialLedger?->transaction_type,
                    'payment_mode' => $allocation->financialLedger?->payment_mode,
                    'narration' => $allocation->financialLedger?->narration,
                ];
            })->values(),
            'linkedReturns' => $purchaseInvoice->purchaseReturns
                ->sortByDesc(fn ($purchaseReturn) => sprintf(
                    '%02d|%s|%010d',
                    match ($purchaseReturn->workflow_status) {
                        'approved' => 3,
                        'reversed' => 2,
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
                    'status' => $purchaseReturn->workflow_status,
                    'total_amount' => round((float) $purchaseReturn->total_amount, 2),
                    'created_by_name' => $purchaseReturn->createdBy?->name,
                    'reversed_by_name' => $purchaseReturn->reversedBy?->name,
                    'reversed_at' => optional($purchaseReturn->reversed_at)?->format('Y-m-d H:i'),
                    'reversal_reason' => $purchaseReturn->reversal_reason,
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
        } catch (\DomainException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
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
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
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
            $invoice->supplier?->name ?? '',
            ucfirst($invoice->status),
            round((float) $invoice->total_amount, 2),
            strtoupper((string) $invoice->tax_type),
            round((float) $invoice->cgst_amount, 2),
            round((float) $invoice->sgst_amount, 2),
            round((float) $invoice->igst_amount, 2),
            $invoice->createdBy?->name ?? '',
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

        $approvedReturns = $purchaseInvoice->purchaseReturns->filter(fn ($purchaseReturn) => $purchaseReturn->isApprovedActive());
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
