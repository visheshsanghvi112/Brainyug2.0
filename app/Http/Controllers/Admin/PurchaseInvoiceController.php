<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\HsnMaster;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
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

        return Inertia::render('Procurement/PurchaseInvoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
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
                'discount_amount' => 0,
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

        $purchaseInvoice->load('items');

        return Inertia::render('Procurement/PurchaseInvoices/CreateEdit', [
            'invoice' => $purchaseInvoice,
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'gst_number']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku', 'mrp', 'hsn_id']),
            'hsn_codes' => HsnMaster::orderBy('hsn_code')->get(['id', 'hsn_code', 'cgst_percent', 'sgst_percent', 'igst_percent']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
        ]);
    }

    /**
     * APPROVE a purchase invoice → auto-create inventory ledger entries.
     * This is the critical step that actually adds stock to the warehouse.
     *
     * Legacy: Purchase_challan → update_data → direct INSERT/UPDATE tbl_stock
     */
    public function approve(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'draft') {
            return back()->with('error', 'Only draft invoices can be approved.');
        }

        $purchaseInvoice->load('items');

        // Block approval if any line item has an already-expired batch.
        // Better than legacy: legacy silently stocked expired items.
        $expiredItems = $purchaseInvoice->items->filter(function ($item) {
            return $item->expiry_date && \Carbon\Carbon::parse($item->expiry_date)->isPast();
        });

        if ($expiredItems->isNotEmpty()) {
            $list = $expiredItems->map(fn($i) => "{$i->batch_no} (exp: {$i->expiry_date})")->join(', ');
            return back()->with('error', "Cannot approve: the following batches are already expired — {$list}. Remove or correct them first.");
        }

        // Collect near-expiry warnings (within 90 days) for display — not a blocker.
        $nearExpiry = $purchaseInvoice->items->filter(function ($item) {
            return $item->expiry_date
                && !$item->expiry_date->isPast()
                && \Carbon\Carbon::parse($item->expiry_date)->diffInDays(now()) <= 90;
        });

        DB::transaction(function () use ($purchaseInvoice, $request) {
            $purchaseInvoice->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            foreach ($purchaseInvoice->items as $item) {
                $this->inventoryService->recordPurchase([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mfg_date' => $item->mfg_date,
                    'mrp' => $item->mrp,
                    'qty' => $item->qty,
                    'free_qty' => $item->free_qty,
                    'rate' => $item->rate,
                    'reference_id' => $purchaseInvoice->id,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        $message = 'Invoice approved. Stock added to warehouse.';
        if ($nearExpiry->isNotEmpty()) {
            $message .= ' ⚠ Warning: ' . $nearExpiry->count() . ' batch(es) expire within 90 days.';
        }

        return back()->with('success', $message);
    }

    /**
     * Cancel a purchase invoice.
     */
    public function cancel(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status === 'cancelled') {
            return back()->with('error', 'Invoice is already cancelled.');
        }

        // If approved, we need reverse entries
        if ($purchaseInvoice->status === 'approved') {
            DB::transaction(function () use ($purchaseInvoice) {
                foreach ($purchaseInvoice->items as $item) {
                    $this->inventoryService->recordAdjustment([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'expiry_date' => $item->expiry_date,
                        'mrp' => $item->mrp,
                        'location_type' => 'warehouse',
                        'location_id' => 0,
                        'qty' => -($item->qty + $item->free_qty), // negative = stock out
                        'rate' => $item->rate,
                        'created_by' => auth()->id(),
                        'remarks' => "Reversal: Purchase Invoice {$purchaseInvoice->invoice_number} cancelled",
                    ]);
                }

                $purchaseInvoice->update(['status' => 'cancelled']);
            });
        } else {
            $purchaseInvoice->update(['status' => 'cancelled']);
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
