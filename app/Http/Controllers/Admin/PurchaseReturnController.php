<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\PurchaseReturnLifecycleService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private PurchaseReturnLifecycleService $purchaseReturnLifecycleService,
        private ReportExportService $reportExportService
    ) {}

    public function index(Request $request)
    {
        $returns = PurchaseReturn::with(['supplier', 'purchaseInvoice', 'sourceInvoices:id,invoice_number', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('return_number', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, function ($query, $status) {
                if ($status === 'reversed') {
                    $query->whereNotNull('reversed_at');
                    return;
                }

                $query->where('status', $status)
                    ->when($status === 'approved', fn ($approvedQuery) => $approvedQuery->whereNull('reversed_at'));
            })
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest()
            ->paginate(15)
            ->through(fn (PurchaseReturn $purchaseReturn) => [
                'id' => $purchaseReturn->id,
                'return_number' => $purchaseReturn->return_number,
                'supplier_id' => $purchaseReturn->supplier_id,
                'purchase_invoice_id' => $purchaseReturn->purchase_invoice_id,
                'return_date' => optional($purchaseReturn->return_date)?->format('Y-m-d'),
                'total_amount' => round((float) $purchaseReturn->total_amount, 2),
                'status' => $purchaseReturn->workflow_status,
                'supplier' => $purchaseReturn->supplier ? [
                    'id' => $purchaseReturn->supplier->id,
                    'name' => $purchaseReturn->supplier->name,
                ] : null,
                'purchase_invoice' => $purchaseReturn->purchaseInvoice ? [
                    'id' => $purchaseReturn->purchaseInvoice->id,
                    'invoice_number' => $purchaseReturn->purchaseInvoice->invoice_number,
                ] : null,
                'source_invoices' => $purchaseReturn->sourceInvoices
                    ->map(fn ($invoice) => [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ])
                    ->values(),
            ])
            ->withQueryString();

        return Inertia::render('Procurement/PurchaseReturns/Index', [
            'returns' => $returns,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        $prefillInvoice = null;

        if ($request->filled('purchase_invoice_id')) {
            $invoice = PurchaseInvoice::approved()
                ->with(['supplier', 'items.product'])
                ->findOrFail((int) $request->input('purchase_invoice_id'));

            $alreadyReturnedByKey = PurchaseReturnItem::query()
                ->selectRaw('purchase_return_items.product_id, purchase_return_items.batch_no, COALESCE(SUM(purchase_return_items.qty), 0) as returned_qty')
                ->join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_items.purchase_return_id')
                ->where('pr.purchase_invoice_id', $invoice->id)
                ->where('pr.status', 'approved')
                ->whereNull('pr.reversed_at')
                ->groupBy('purchase_return_items.product_id', 'purchase_return_items.batch_no')
                ->get()
                ->mapWithKeys(fn($row) => [
                    $row->product_id . '|' . $row->batch_no => (float) $row->returned_qty,
                ]);

            $prefillItems = collect($invoice->items)
                ->groupBy(fn($item) => $item->product_id . '|' . $item->batch_no)
                ->map(function ($group, $key) use ($alreadyReturnedByKey) {
                    $first = $group->first();
                    $purchasedQty = (float) $group->sum(fn($line) => (float) $line->qty + (float) $line->free_qty);
                    $returnedQty = (float) ($alreadyReturnedByKey[$key] ?? 0);
                    $remainingQty = round(max(0, $purchasedQty - $returnedQty), 2);

                    if ($remainingQty <= 0) {
                        return null;
                    }

                    return [
                        'product_id' => $first->product_id,
                        'product_name' => $first->product?->product_name,
                        'batch_no' => $first->batch_no,
                        'expiry_date' => optional($first->expiry_date)?->format('Y-m-d'),
                        'qty' => $remainingQty,
                        'max_qty' => $remainingQty,
                        'rate' => round((float) $first->rate, 4),
                        'gst_percent' => round((float) $first->gst_percent, 2),
                        'reason' => '',
                    ];
                })
                ->filter()
                ->values();

            if ($prefillItems->isEmpty()) {
                return redirect()
                    ->route('admin.purchase-invoices.show', $invoice->id)
                    ->with('error', "Invoice {$invoice->invoice_number} has already been fully returned. No new purchase return can be raised from it.");
            }

            $prefillInvoice = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier?->name,
                'items' => $prefillItems,
            ];
        }

        return Inertia::render('Procurement/PurchaseReturns/CreateEdit', [
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'invoices' => PurchaseInvoice::approved()->latest()->take(50)->get(['id', 'invoice_number', 'supplier_id']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
            'prefillInvoice' => $prefillInvoice,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|exists:purchase_invoices,id',
            'source_invoice_ids' => 'nullable|array',
            'source_invoice_ids.*' => 'integer|exists:purchase_invoices,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.gst_percent' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string',
        ]);

        $sourceInvoiceIds = collect($validated['source_invoice_ids'] ?? [])
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        if (!empty($validated['purchase_invoice_id'])) {
            $sourceInvoiceIds->push((int) $validated['purchase_invoice_id']);
        }

        $sourceInvoiceIds = $sourceInvoiceIds->unique()->values();

        $sourceInvoices = collect();
        if ($sourceInvoiceIds->isNotEmpty()) {
            $sourceInvoices = PurchaseInvoice::with('items')
                ->whereIn('id', $sourceInvoiceIds)
                ->get();

            if ($sourceInvoices->count() !== $sourceInvoiceIds->count()) {
                return back()->withErrors([
                    'source_invoice_ids' => 'One or more selected source invoices could not be loaded.',
                ])->withInput();
            }

            if ($sourceInvoices->contains(fn ($invoice) => $invoice->status !== 'approved')) {
                return back()->withErrors([
                    'source_invoice_ids' => 'Only approved purchase invoices can be used as source invoices for a purchase return.',
                ])->withInput();
            }

            if ($sourceInvoices->contains(fn ($invoice) => (int) $invoice->supplier_id !== (int) $validated['supplier_id'])) {
                return back()->withErrors([
                    'source_invoice_ids' => 'All selected source invoices must belong to the selected supplier.',
                ])->withInput();
            }

            $taxTypes = $sourceInvoices->pluck('tax_type')->filter()->unique();
            if ($taxTypes->count() > 1) {
                return back()->withErrors([
                    'source_invoice_ids' => 'Selected source invoices have mixed tax types. Please select invoices with the same tax type.',
                ])->withInput();
            }

            $this->purchaseReturnLifecycleService->validateInvoicesLinkedReturnItems($sourceInvoices, $validated['items']);
        }

        DB::transaction(function () use ($validated, $request, $sourceInvoices, $sourceInvoiceIds) {
            $financialYear = PurchaseInvoice::financialYearForDate($validated['return_date']);

            // Generate return number
            $lastReturn = PurchaseReturn::where('financial_year', $financialYear)
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastReturn ? ((int) substr($lastReturn->return_number, -4)) + 1 : 1;
            $returnNumber = 'PR-' . $financialYear . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            $subtotal = 0;
            $totalSgst = 0;
            $totalCgst = 0;
            $totalIgst = 0;
            $itemsData = [];

            // We assume intra-state if no invoice linked, or we could fetch supplier state vs HO state.
            // For simplicity, if linked to invoice, use its tax type, else default intra.
            $taxType = 'intra_state';
            if ($sourceInvoices->isNotEmpty()) {
                $taxType = (string) $sourceInvoices->first()->tax_type;
            }

            $invoiceLineMap = $sourceInvoices->isNotEmpty()
                ? $sourceInvoices->flatMap(fn ($invoice) => $invoice->items)
                    ->groupBy(fn ($item) => $item->product_id . '|' . $item->batch_no)
                : collect();

            foreach ($validated['items'] as $item) {
                $qty = (float) $item['qty'];
                $rate = (float) $item['rate'];
                $gstPct = (float) $item['gst_percent'];
                $expiryDate = $item['expiry_date'] ?? null;

                if ($sourceInvoices->isNotEmpty()) {
                    $key = $item['product_id'] . '|' . $item['batch_no'];
                    $invoiceLines = $invoiceLineMap->get($key);

                    if (!$invoiceLines || $invoiceLines->isEmpty()) {
                        throw ValidationException::withMessages([
                            'items' => "Return item product {$item['product_id']} batch {$item['batch_no']} does not exist on selected source invoices.",
                        ]);
                    }

                    // Keep linked returns commercially consistent with original invoice lines.
                    $weightedBase = (float) $invoiceLines->sum(fn ($line) => (float) $line->qty);
                    $weightedRateTotal = (float) $invoiceLines->sum(fn ($line) => (float) $line->qty * (float) $line->rate);
                    $rate = $weightedBase > 0 ? round($weightedRateTotal / $weightedBase, 4) : (float) $invoiceLines->first()->rate;
                    $gstPct = (float) $invoiceLines->first()->gst_percent;
                    $expiryDate = $invoiceLines->first()->expiry_date;
                }

                $taxable = $qty * $rate;
                $gstAmt = $taxable * ($gstPct / 100);

                if ($taxType === 'intra_state') {
                    $totalSgst += $gstAmt / 2;
                    $totalCgst += $gstAmt / 2;
                } else {
                    $totalIgst += $gstAmt;
                }

                $subtotal += $taxable;
                $lineTotal = $taxable + $gstAmt;

                // Validate stock availability BEFORE allowing return

                $itemsData[] = array_merge($item, [
                    'rate' => round($rate, 4),
                    'gst_percent' => round($gstPct, 2),
                    'expiry_date' => $expiryDate,
                    'gst_amount' => round($gstAmt, 2),
                    'total_amount' => round($lineTotal, 2),
                ]);
            }

            $totalAmount = $subtotal + $totalSgst + $totalCgst + $totalIgst;

            $purchaseReturn = PurchaseReturn::create([
                'return_number' => $returnNumber,
                'supplier_id' => $validated['supplier_id'],
                'purchase_invoice_id' => $sourceInvoiceIds->isNotEmpty()
                    ? (int) $sourceInvoiceIds->first()
                    : ($validated['purchase_invoice_id'] ?? null),
                'return_date' => $validated['return_date'],
                'financial_year' => $financialYear,
                'subtotal' => round($subtotal, 2),
                'sgst_amount' => round($totalSgst, 2),
                'cgst_amount' => round($totalCgst, 2),
                'igst_amount' => round($totalIgst, 2),
                'total_amount' => round($totalAmount, 2),
                'status' => 'draft',
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $item) {
                $purchaseReturn->items()->create($item);
            }

            if ($sourceInvoiceIds->isNotEmpty()) {
                $purchaseReturn->sourceInvoices()->sync($sourceInvoiceIds->all());
            }
        });

        return redirect()->route('admin.purchase-returns.index')
            ->with('success', 'Purchase return created as draft.');
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseInvoice', 'sourceInvoices:id,invoice_number', 'items.product', 'createdBy', 'approvedBy', 'reversedBy']);

        return Inertia::render('Procurement/PurchaseReturns/Show', [
            'purchaseReturn' => $purchaseReturn,
            'actions' => [
                'can_approve' => $purchaseReturn->status === 'draft',
                'can_cancel' => $purchaseReturn->status === 'draft',
                'can_reverse' => $purchaseReturn->canReverse(),
            ],
        ]);
    }

    /**
     * APPROVE a purchase return → auto-create inventory ledger entries (DEDUCT STOCK).
     */
    public function approve(Request $request, PurchaseReturn $purchaseReturn)
    {
        try {
            $this->purchaseReturnLifecycleService->approve($purchaseReturn, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return approved. Stock deducted from warehouse and supplier payable reduced.');
    }

    public function cancel(PurchaseReturn $purchaseReturn)
    {
        try {
            $this->purchaseReturnLifecycleService->cancel($purchaseReturn);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Return cancelled.');
    }

    public function reverse(Request $request, PurchaseReturn $purchaseReturn)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->purchaseReturnLifecycleService->reverse($purchaseReturn, $request->user(), $validated['reason'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Purchase return reversed. Warehouse stock restored and supplier payable reopened.');
    }

    /**
     * Export purchase returns to CSV.
     */
    public function export(Request $request)
    {
        $query = PurchaseReturn::with(['supplier', 'purchaseInvoice', 'sourceInvoices:id,invoice_number', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('return_number', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, function ($query, $status) {
                if ($status === 'reversed') {
                    $query->whereNotNull('reversed_at');
                    return;
                }

                $query->where('status', $status)
                    ->when($status === 'approved', fn ($approvedQuery) => $approvedQuery->whereNull('reversed_at'));
            })
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest();

        $returns = $query->get();
        $exportHeaders = [
            'Return No', 'Date', 'Supplier', 'Linked Invoice', 'Status', 'Total Amount',
            'CGST', 'SGST', 'IGST', 'Created By',
        ];

        $rows = $returns->map(fn (PurchaseReturn $return) => [
            $return->return_number,
            optional($return->return_date)?->format('Y-m-d'),
            $return->supplier?->name ?? '',
            $return->sourceInvoices->pluck('invoice_number')->join(', ') ?: $return->purchaseInvoice?->invoice_number,
            ucfirst($return->workflow_status),
            round((float) $return->total_amount, 2),
            round((float) $return->cgst_amount, 2),
            round((float) $return->sgst_amount, 2),
            round((float) $return->igst_amount, 2),
            $return->createdBy?->name ?? '',
        ])->all();

        $summary = [
            'Returns' => $returns->count(),
            'Draft' => $returns->where('status', 'draft')->count(),
            'Approved' => $returns->filter(fn (PurchaseReturn $return) => $return->isApprovedActive())->count(),
            'Reversed' => $returns->whereNotNull('reversed_at')->count(),
            'Cancelled' => $returns->where('status', 'cancelled')->count(),
            'Total Amount' => round((float) $returns->sum('total_amount'), 2),
        ];

        $format = strtolower((string) $request->input('format', 'csv'));

        if ($format === 'excel') {
            return $this->reportExportService->downloadExcel(
                fileBase: 'purchase_returns',
                sheetTitle: 'Purchase Returns',
                headers: $exportHeaders,
                rows: $rows,
                meta: $summary,
            );
        }

        if ($format === 'pdf') {
            return $this->reportExportService->downloadPdf(
                fileBase: 'purchase_returns',
                title: 'Purchase Returns',
                headers: $exportHeaders,
                rows: $rows,
                meta: $summary,
            );
        }

        $filename = 'purchase_returns_' . date('Y-m-d_H-i-s') . '.csv';

        $responseHeaders = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
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
     * Print view for a purchase return.
     */
    public function print(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseInvoice', 'items.product', 'createdBy', 'approvedBy', 'reversedBy']);

        return Inertia::render('Procurement/PurchaseReturns/Print', [
            'purchaseReturn' => $purchaseReturn,
        ]);
    }
}
