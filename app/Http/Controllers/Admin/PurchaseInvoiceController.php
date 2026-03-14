<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\HsnMaster;
use App\Models\DistOrder;
use App\Services\InventoryService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private LedgerService $ledgerService
    ) {}

    public function index(Request $request)
    {
        $invoices = PurchaseInvoice::with(['supplier', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('invoice_number', 'like', "%{$search}%")
                       ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $orderOpsMetrics = [
            'pending_orders' => DistOrder::where('status', 'pending')->count(),
            'pending_dispatch' => DistOrder::where('status', 'accepted')->count(),
            'in_transit' => DistOrder::where('status', 'dispatched')->count(),
            'open_work' => DistOrder::whereIn('status', ['pending', 'accepted', 'dispatched'])->count(),
        ];

        $pendingOrderOps = DistOrder::with(['franchisee'])
            ->whereIn('status', ['pending', 'accepted', 'dispatched'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'dispatched' THEN 2 ELSE 3 END")
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
        $validated = $request->validate([
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

        // Better than legacy: give a clear duplicate error before DB constraint kicks in
        if (!empty($validated['supplier_invoice_no'])) {
            $fy = PurchaseInvoice::currentFinancialYear();
            $duplicate = PurchaseInvoice::where('supplier_id', $validated['supplier_id'])
                ->where('supplier_invoice_no', $validated['supplier_invoice_no'])
                ->where('financial_year', $fy)
                ->whereNot('status', 'cancelled')
                ->first();

            if ($duplicate) {
                return back()->withErrors([
                    'supplier_invoice_no' => "Invoice #{$validated['supplier_invoice_no']} already exists for this supplier in FY {$fy}. (Ref: {$duplicate->invoice_number})",
                ])->withInput();
            }
        }

        DB::transaction(function () use ($validated, $request) {
            // Generate invoice number
            $lastInvoice = PurchaseInvoice::where('financial_year', PurchaseInvoice::currentFinancialYear())
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, -4)) + 1 : 1;
            $invoiceNumber = 'PI-' . PurchaseInvoice::currentFinancialYear() . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            // Calculate totals
            $subtotal = 0;
            $totalDiscount = 0;
            $totalSgst = 0;
            $totalCgst = 0;
            $totalIgst = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = $item['qty'];
                $rate = $item['rate'];
                $discPct = $item['discount_percent'] ?? 0;
                $gstPct = $item['gst_percent'];

                $lineTotal = $qty * $rate;
                $discAmt = $lineTotal * ($discPct / 100);
                $taxable = $lineTotal - $discAmt;
                $gstAmt = $taxable * ($gstPct / 100);
                $totalDiscount += $discAmt;

                if ($validated['tax_type'] === 'intra_state') {
                    $totalSgst += $gstAmt / 2;
                    $totalCgst += $gstAmt / 2;
                } else {
                    $totalIgst += $gstAmt;
                }

                $subtotal += $taxable;

                $itemsData[] = array_merge($item, [
                    'discount_amount' => round($discAmt, 2),
                    'gst_amount' => round($gstAmt, 2),
                    'taxable_amount' => round($taxable, 2),
                    'total_amount' => round($taxable + $gstAmt, 2),
                ]);
            }

            $totalAmount = $subtotal + $totalSgst + $totalCgst + $totalIgst;
            $roundOff = round($totalAmount) - $totalAmount;

            $invoice = PurchaseInvoice::create([
                'invoice_number' => $invoiceNumber,
                'supplier_id' => $validated['supplier_id'],
                'supplier_invoice_no' => $validated['supplier_invoice_no'] ?? null,
                'invoice_date' => $validated['invoice_date'],
                'received_date' => $validated['received_date'] ?? null,
                'due_days' => $validated['due_days'] ?? 0,
                'transporter' => $validated['transporter'] ?? null,
                'lr_number' => $validated['lr_number'] ?? null,
                'financial_year' => PurchaseInvoice::currentFinancialYear(),
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($totalDiscount, 2),
                'sgst_amount' => round($totalSgst, 2),
                'cgst_amount' => round($totalCgst, 2),
                'igst_amount' => round($totalIgst, 2),
                'round_off' => round($roundOff, 2),
                'total_amount' => round($totalAmount + $roundOff, 2),
                'tax_type' => $validated['tax_type'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($itemsData as $item) {
                $invoice->items()->create($item);
            }
        });

        return redirect()->route('admin.purchase-invoices.index')
            ->with('success', 'Purchase invoice created as draft.');
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load(['supplier', 'items.product', 'items.hsn', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseInvoices/Show', [
            'invoice' => $purchaseInvoice,
        ]);
    }

    public function edit(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'draft') {
            return back()->with('error', 'Only draft invoices can be edited.');
        }
            if ($purchaseInvoice->status === 'legacy') {
                return back()->with('error', 'Legacy historical invoices are read-only and cannot be edited.');
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
        if ($purchaseInvoice->status !== 'draft') {
            return back()->with('error', 'Only draft invoices can be updated.');
        }
            if ($purchaseInvoice->status === 'legacy') {
                return back()->with('error', 'Legacy historical invoices are read-only and cannot be updated.');
            }

        $validated = $request->validate([
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

        DB::transaction(function () use ($purchaseInvoice, $validated) {
            $locked = PurchaseInvoice::whereKey($purchaseInvoice->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'This purchase invoice is no longer draft and cannot be edited.',
                ]);
            }

            $subtotal = 0;
            $totalDiscount = 0;
            $totalSgst = 0;
            $totalCgst = 0;
            $totalIgst = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = $item['qty'];
                $rate = $item['rate'];
                $discPct = $item['discount_percent'] ?? 0;
                $gstPct = $item['gst_percent'];

                $lineTotal = $qty * $rate;
                $discAmt = $lineTotal * ($discPct / 100);
                $taxable = $lineTotal - $discAmt;
                $gstAmt = $taxable * ($gstPct / 100);
                $totalDiscount += $discAmt;

                if ($validated['tax_type'] === 'intra_state') {
                    $totalSgst += $gstAmt / 2;
                    $totalCgst += $gstAmt / 2;
                } else {
                    $totalIgst += $gstAmt;
                }

                $subtotal += $taxable;

                $itemsData[] = array_merge($item, [
                    'discount_amount' => round($discAmt, 2),
                    'gst_amount' => round($gstAmt, 2),
                    'taxable_amount' => round($taxable, 2),
                    'total_amount' => round($taxable + $gstAmt, 2),
                ]);
            }

            $totalAmount = $subtotal + $totalSgst + $totalCgst + $totalIgst;
            $roundOff = round($totalAmount) - $totalAmount;

            $locked->update([
                'supplier_id' => $validated['supplier_id'],
                'supplier_invoice_no' => $validated['supplier_invoice_no'] ?? null,
                'invoice_date' => $validated['invoice_date'],
                'received_date' => $validated['received_date'] ?? null,
                'due_days' => $validated['due_days'] ?? 0,
                'transporter' => $validated['transporter'] ?? null,
                'lr_number' => $validated['lr_number'] ?? null,
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($totalDiscount, 2),
                'sgst_amount' => round($totalSgst, 2),
                'cgst_amount' => round($totalCgst, 2),
                'igst_amount' => round($totalIgst, 2),
                'round_off' => round($roundOff, 2),
                'total_amount' => round($totalAmount + $roundOff, 2),
                'tax_type' => $validated['tax_type'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $locked->items()->delete();
            foreach ($itemsData as $item) {
                $locked->items()->create($item);
            }
        });

        return redirect()->route('admin.purchase-invoices.show', $purchaseInvoice)
            ->with('success', 'Purchase invoice updated successfully.');
    }

    /**
     * APPROVE a purchase invoice → auto-create inventory ledger entries.
     * This is the critical step that actually adds stock to the warehouse.
     *
     * Legacy: Purchase_challan → update_data → direct INSERT/UPDATE tbl_stock
     */
    public function approve(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $actor = $request->user();
        $nearExpiryCount = 0;

        try {
            DB::transaction(function () use ($purchaseInvoice, $actor, &$nearExpiryCount) {
                $lockedInvoice = PurchaseInvoice::whereKey($purchaseInvoice->id)
                    ->lockForUpdate()
                    ->with(['items', 'supplier'])
                    ->firstOrFail();

                if ($lockedInvoice->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'status' => 'Only draft invoices can be approved.',
                    ]);
                }

                // Maker-checker: creator cannot approve own invoice unless Super Admin.
                if ((int) $lockedInvoice->created_by === (int) $actor->id && !$actor->isSuperAdmin()) {
                    throw ValidationException::withMessages([
                        'approval' => 'Maker-checker rule: the creator cannot approve this invoice. Ask another authorized user to approve.',
                    ]);
                }

                $expiredItems = $lockedInvoice->items->filter(function ($item) {
                    return $item->expiry_date && \Carbon\Carbon::parse($item->expiry_date)->isPast();
                });

                if ($expiredItems->isNotEmpty()) {
                    $list = $expiredItems->map(fn($i) => "{$i->batch_no} (exp: {$i->expiry_date})")->join(', ');
                    throw ValidationException::withMessages([
                        'items' => "Cannot approve: the following batches are already expired — {$list}. Remove or correct them first.",
                    ]);
                }

                $nearExpiryCount = $lockedInvoice->items->filter(function ($item) {
                    return $item->expiry_date
                        && !\Carbon\Carbon::parse($item->expiry_date)->isPast()
                        && \Carbon\Carbon::parse($item->expiry_date)->diffInDays(now()) <= 90;
                })->count();

                $lockedInvoice->update([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);

                foreach ($lockedInvoice->items as $item) {
                $this->inventoryService->recordPurchase([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mfg_date' => $item->mfg_date,
                    'mrp' => $item->mrp,
                    'qty' => $item->qty,
                    'free_qty' => $item->free_qty,
                    'rate' => $item->rate,
                    'reference_id' => $lockedInvoice->id,
                    'created_by' => $actor->id,
                ]);
            }

            $this->ledgerService->recordEntry(
                $lockedInvoice->supplier,
                'PURCHASE',
                debit: 0,
                credit: (float) $lockedInvoice->total_amount,
                reference: $lockedInvoice,
                paymentMode: 'credit',
                narration: "Purchase invoice {$lockedInvoice->invoice_number} approved for {$lockedInvoice->supplier->name}",
                transactionDate: $lockedInvoice->invoice_date,
            );
            });
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
    public function cancel(PurchaseInvoice $purchaseInvoice)
    {
        try {
            DB::transaction(function () use ($purchaseInvoice) {
                $lockedInvoice = PurchaseInvoice::whereKey($purchaseInvoice->id)
                    ->lockForUpdate()
                    ->with(['items', 'supplier'])
                    ->firstOrFail();

                if ($lockedInvoice->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        'status' => 'Invoice is already cancelled.',
                    ]);
                }

                    if ($lockedInvoice->status === 'legacy') {
                        throw ValidationException::withMessages([
                            'status' => 'Legacy historical invoices are read-only archives and cannot be cancelled. If this stock no longer exists, create a manual stock adjustment instead.',
                        ]);
                    }

                if ($lockedInvoice->status === 'approved') {
                    foreach ($lockedInvoice->items as $item) {
                        $requiredQty = (float) $item->qty + (float) $item->free_qty;

                        if (!$this->inventoryService->hasSufficientStock(
                            (int) $item->product_id,
                            (string) $item->batch_no,
                            'warehouse',
                            0,
                            $requiredQty
                        )) {
                            throw ValidationException::withMessages([
                                'status' => "Cannot cancel approved invoice {$lockedInvoice->invoice_number}: stock for product {$item->product_id} batch {$item->batch_no} has already been consumed. Use purchase return or stock adjustment workflow instead.",
                            ]);
                        }
                    }

                    foreach ($lockedInvoice->items as $item) {
                        $this->inventoryService->recordAdjustment([
                            'product_id' => $item->product_id,
                            'batch_no' => $item->batch_no,
                            'expiry_date' => $item->expiry_date,
                            'mrp' => $item->mrp,
                            'location_type' => 'warehouse',
                            'location_id' => 0,
                            'qty' => -((float) $item->qty + (float) $item->free_qty), // negative = stock out
                            'rate' => $item->rate,
                            'created_by' => auth()->id(),
                            'remarks' => "Reversal: Purchase Invoice {$lockedInvoice->invoice_number} cancelled",
                        ]);
                    }

                    $this->ledgerService->recordEntry(
                        $lockedInvoice->supplier,
                        'PURCHASE_CANCELLED',
                        debit: (float) $lockedInvoice->total_amount,
                        credit: 0,
                        reference: $lockedInvoice,
                        paymentMode: 'adjustment',
                        narration: "Purchase invoice {$lockedInvoice->invoice_number} cancelled and payable reversed",
                        transactionDate: now(),
                    );

                    $lockedInvoice->update(['status' => 'cancelled']);
                    return;
                }

                $lockedInvoice->update(['status' => 'cancelled']);
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Invoice cancelled.');
    }

    /**
     * Export purchase invoices to CSV (Excel).
     */
    public function export(Request $request)
    {
        $query = PurchaseInvoice::with(['supplier', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('invoice_number', 'like', "%{$search}%")
                       ->orWhere('supplier_invoice_no', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest();

        $invoices = $query->get();

        $filename = "purchase_invoices_" . date('Y-m-d_H-i-s') . ".csv";

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'System Inv No', 'Supplier Inv No', 'Date', 'Supplier', 'Status', 
            'Total Amount', 'Tax Type', 'CGST', 'SGST', 'IGST', 'Created By'
        ];

        $callback = function () use ($invoices, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($invoices as $invoice) {
                fputcsv($file, [
                    $invoice->invoice_number,
                    $invoice->supplier_invoice_no,
                    $invoice->invoice_date->format('Y-m-d'),
                    $invoice->supplier->name ?? '',
                    ucfirst($invoice->status),
                    $invoice->total_amount,
                    strtoupper($invoice->tax_type),
                    $invoice->cgst_amount,
                    $invoice->sgst_amount,
                    $invoice->igst_amount,
                    $invoice->createdBy->name ?? '',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Print View for Purchase Invoice
     */
    public function print(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load(['supplier', 'items.product', 'items.hsn', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseInvoices/Print', [
            'invoice' => $purchaseInvoice,
        ]);
    }
}
