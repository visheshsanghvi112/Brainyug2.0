<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PurchaseOrderSentMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Services\PurchaseInvoiceLifecycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderController extends Controller
{
    protected $invoiceService;

    public function __construct(PurchaseInvoiceLifecycleService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Display list of purchase orders
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'createdBy', 'approvedBy'])
            ->orderBy('created_at', 'desc');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%$search%")
                  ->orWhereHas('supplier', function($sq) use ($search) {
                      $sq->where('name', 'like', "%$search%");
                  });
            });
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        $purchaseOrders = $query->paginate(20);

        $suppliers = Supplier::orderBy('name')->get(['id', 'name', 'code']);
        $statuses = ['draft', 'approved', 'sent', 'received', 'invoiced', 'cancelled'];

        return Inertia::render('Procurement/PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'suppliers' => $suppliers,
            'statuses' => $statuses,
            'filters' => $request->only('status', 'supplier_id', 'search', 'date_from', 'date_to'),
        ]);
    }

    /**
     * Show create form
     */
    public function create()
    {
        $suppliers = Supplier::orderBy('name')->get();
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'sku', 'mrp', 'ptr', 'rate_a', 'unit']);

        $currentFY = $this->getCurrentFinancialYear();
        $nextOrderNumber = PurchaseOrder::generateNextOrderNumber($currentFY);

        return Inertia::render('Procurement/PurchaseOrders/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'orderNumber' => $nextOrderNumber,
            'currentFY' => $currentFY,
        ]);
    }

    /**
     * Store new purchase order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'required_date' => 'nullable|date|after:order_date',
            'tax_type' => 'required|in:intra_state,inter_state',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.gst_percent' => 'required|numeric|min:0|max:100',
            'items.*.unit' => 'required|string',
            'items.*.mfg_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.batch_no' => 'nullable|string',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $currentFY = $this->getCurrentFinancialYear();
            
            $purchaseOrder = PurchaseOrder::create([
                'order_number' => PurchaseOrder::generateNextOrderNumber($currentFY),
                'supplier_id' => $validated['supplier_id'],
                'order_date' => $validated['order_date'],
                'required_date' => $validated['required_date'] ?? null,
                'expected_delivery_date' => $validated['required_date'] ?? null,
                'financial_year' => $currentFY,
                'tax_type' => $validated['tax_type'],
                'status' => 'draft',
                'created_by' => Auth::id(),
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create line items
            foreach ($validated['items'] as $item) {
                $lineItem = new PurchaseOrderItem($item);
                $lineItem->calculateLineAmount();
                $purchaseOrder->items()->save($lineItem);
            }

            // Calculate totals
            $purchaseOrder->calculateTotals();
            $purchaseOrder->save();

            DB::commit();

            return redirect()->route('admin.purchase-orders.show', $purchaseOrder)
                ->with('success', "Purchase Order {$purchaseOrder->order_number} created successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to create purchase order: ' . $e->getMessage()]);
        }
    }

    /**
     * Show purchase order detail
     */
    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'items.product', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->withErrors(['error' => 'Only draft purchase orders can be edited.']);
        }

        $purchaseOrder->load(['supplier', 'items']);
        $suppliers = Supplier::orderBy('name')->get();
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'sku', 'mrp', 'ptr', 'rate_a', 'unit']);

        return Inertia::render('Procurement/PurchaseOrders/Edit', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => $suppliers,
            'products' => $products,
        ]);
    }

    /**
     * Update purchase order
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->withErrors(['error' => 'Only draft purchase orders can be updated.']);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'required_date' => 'nullable|date|after:order_date',
            'tax_type' => 'required|in:intra_state,inter_state',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.gst_percent' => 'required|numeric|min:0|max:100',
            'items.*.unit' => 'required|string',
            'items.*.mfg_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.batch_no' => 'nullable|string',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Update header
            $purchaseOrder->update([
                'supplier_id' => $validated['supplier_id'],
                'order_date' => $validated['order_date'],
                'required_date' => $validated['required_date'] ?? null,
                'expected_delivery_date' => $validated['required_date'] ?? null,
                'tax_type' => $validated['tax_type'],
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Handle line items
            $existingIds = collect($validated['items'])->pluck('id')->filter()->toArray();
            $purchaseOrder->items()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['items'] as $item) {
                if (isset($item['id'])) {
                    $lineItem = $purchaseOrder->items()->find($item['id']);
                    $lineItem->update($item);
                } else {
                    $lineItem = new PurchaseOrderItem($item);
                    $purchaseOrder->items()->save($lineItem);
                }
                $lineItem->calculateLineAmount();
                $lineItem->save();
            }

            // Recalculate totals
            $purchaseOrder->calculateTotals();
            $purchaseOrder->save();

            DB::commit();

            return redirect()->route('admin.purchase-orders.show', $purchaseOrder)
                ->with('success', 'Purchase Order updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to update purchase order: ' . $e->getMessage()]);
        }
    }

    /**
     * Approve purchase order
     */
    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (!$purchaseOrder->canApprove()) {
            return back()->withErrors(['error' => 'This purchase order cannot be approved.']);
        }

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', "Purchase Order {$purchaseOrder->order_number} approved successfully.");
    }

    /**
     * Send purchase order to supplier
     */
    public function send(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (!$purchaseOrder->canSend()) {
            return back()->withErrors(['error' => 'This purchase order cannot be sent.']);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $purchaseOrder->update([
            'status' => 'sent',
            'sent_at' => now(),
            'notes' => $validated['notes'] ?? $purchaseOrder->notes,
        ]);

        $purchaseOrder->loadMissing(['supplier', 'items.product']);

        $mailSent = false;
        $supplierEmail = $purchaseOrder->supplier?->email;

        if (!empty($supplierEmail)) {
            try {
                Mail::to($supplierEmail)->send(new PurchaseOrderSentMail($purchaseOrder));
                $mailSent = true;
            } catch (\Throwable $exception) {
                Log::warning('Purchase order email failed during send action.', [
                    'purchase_order_id' => $purchaseOrder->id,
                    'order_number' => $purchaseOrder->order_number,
                    'supplier_id' => $purchaseOrder->supplier_id,
                    'supplier_email' => $supplierEmail,
                    'error' => $exception->getMessage(),
                ]);
            }
        } else {
            Log::notice('Purchase order marked as sent without supplier email.', [
                'purchase_order_id' => $purchaseOrder->id,
                'order_number' => $purchaseOrder->order_number,
                'supplier_id' => $purchaseOrder->supplier_id,
            ]);
        }

        $message = $mailSent
            ? 'Purchase Order sent to supplier successfully.'
            : 'Purchase Order marked as sent. Supplier email could not be delivered.';

        return back()->with('success', $message);
    }

    /**
     * Mark purchase order as received (GRN)
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (!$purchaseOrder->canReceive()) {
            return back()->withErrors(['error' => 'This purchase order cannot be received.']);
        }

        $validated = $request->validate([
            'received_date' => 'required|date',
            'transporter' => 'nullable|string',
            'lr_number' => 'nullable|string',
            'transport_cost' => 'nullable|numeric|min:0',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.qty_received' => 'required|integer|min:0',
            'items.*.qty_rejected' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Update PO with receipt info
            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
                'transporter' => $validated['transporter'] ?? null,
                'lr_number' => $validated['lr_number'] ?? null,
                'transport_cost' => $validated['transport_cost'] ?? 0,
            ]);

            // Update item quantities
            foreach ($validated['items'] as $itemData) {
                $item = $purchaseOrder->items()->find($itemData['id']);
                $item->update([
                    'qty_received' => $itemData['qty_received'],
                    'qty_rejected' => $itemData['qty_rejected'],
                ]);
            }

            DB::commit();

            return back()->with('success', 'Purchase Order marked as received. Ready to convert to invoice.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to update received quantities: ' . $e->getMessage()]);
        }
    }

    /**
     * Convert received purchase order to purchase invoice
     */
    public function convertToInvoice(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'received') {
            return back()->withErrors(['error' => 'Only received purchase orders can be converted.']);
        }

        if ($purchaseOrder->purchase_invoice_id) {
            return back()->withErrors(['error' => 'This purchase order has already been converted to an invoice.']);
        }

        $validated = $request->validate([
            'supplier_invoice_no' => 'required|string|max:50',
            'invoice_date' => 'required|date',
            'received_date' => 'required|date',
        ]);

        DB::beginTransaction();
        try {
            $financialYear = PurchaseInvoice::financialYearForDate($validated['invoice_date']);
            $lastInvoice = PurchaseInvoice::query()
                ->where('financial_year', $financialYear)
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastInvoice ? ((int) substr((string) $lastInvoice->invoice_number, -4)) + 1 : 1;

            // Create purchase invoice from PO
            $invoice = PurchaseInvoice::create([
                'invoice_number' => 'PI-' . $financialYear . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT),
                'supplier_invoice_no' => $validated['supplier_invoice_no'],
                'supplier_id' => $purchaseOrder->supplier_id,
                'invoice_date' => $validated['invoice_date'],
                'received_date' => $validated['received_date'],
                'financial_year' => $financialYear,
                'tax_type' => $purchaseOrder->tax_type,
                'subtotal' => 0,
                'discount_amount' => 0,
                'sgst_amount' => 0,
                'cgst_amount' => 0,
                'igst_amount' => 0,
                'total_amount' => 0,
                'status' => 'draft',
                'created_by' => Auth::id(),
                'notes' => "Converted from Purchase Order {$purchaseOrder->order_number}",
            ]);

            $subtotal = 0.0;
            $gstTotal = 0.0;

            // Create invoice items from PO items
            foreach ($purchaseOrder->items as $poItem) {
                $qty = (float) (($poItem->qty_received ?? 0) > 0 ? $poItem->qty_received : $poItem->qty_ordered);
                if ($qty <= 0) {
                    continue;
                }

                $rate = (float) ($poItem->rate ?? 0);
                $discountPercent = (float) ($poItem->discount_percent ?? 0);
                $lineAmount = round($qty * $rate, 2);
                $discountAmount = round(($lineAmount * $discountPercent) / 100, 2);
                $taxableAmount = round(max(0, $lineAmount - $discountAmount), 2);
                $gstPercent = (float) ($poItem->gst_percent ?? 0);
                $gstAmount = round(($taxableAmount * $gstPercent) / 100, 2);
                $totalAmount = round($taxableAmount + $gstAmount, 2);

                $invoiceItem = new PurchaseInvoiceItem([
                    'product_id' => $poItem->product_id,
                    'qty' => $qty,
                    'free_qty' => (float) ($poItem->qty_free ?? 0),
                    'mrp' => $poItem->mrp,
                    'rate' => $poItem->rate,
                    'unit' => $poItem->unit,
                    'gst_percent' => $poItem->gst_percent,
                    'batch_no' => $poItem->batch_no,
                    'mfg_date' => $poItem->mfg_date,
                    'expiry_date' => $poItem->expiry_date,
                    'discount_percent' => $poItem->discount_percent,
                    'discount_amount' => $discountAmount,
                    'gst_amount' => $gstAmount,
                    'taxable_amount' => $taxableAmount,
                    'total_amount' => $totalAmount,
                ]);
                $invoice->items()->save($invoiceItem);

                $subtotal += $taxableAmount;
                $gstTotal += $gstAmount;

                // Link PO item to invoice item
                $poItem->update(['purchase_invoice_item_id' => $invoiceItem->id]);
            }

            $sgstAmount = $invoice->tax_type === 'intra_state' ? round($gstTotal / 2, 2) : 0;
            $cgstAmount = $invoice->tax_type === 'intra_state' ? round($gstTotal / 2, 2) : 0;
            $igstAmount = $invoice->tax_type === 'inter_state' ? round($gstTotal, 2) : 0;

            $invoice->update([
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round((float) $purchaseOrder->discount_amount, 2),
                'sgst_amount' => $sgstAmount,
                'cgst_amount' => $cgstAmount,
                'igst_amount' => $igstAmount,
                'total_amount' => round($subtotal + $sgstAmount + $cgstAmount + $igstAmount - (float) $purchaseOrder->discount_amount, 2),
            ]);

            // Update PO status
            $purchaseOrder->update([
                'status' => 'invoiced',
                'purchase_invoice_id' => $invoice->id,
            ]);

            DB::commit();

            return redirect()->route('admin.purchase-invoices.show', $invoice)
                ->with('success', "Purchase Order converted to Invoice {$invoice->invoice_number} successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to convert to invoice: ' . $e->getMessage()]);
        }
    }

    /**
     * Cancel purchase order
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (!$purchaseOrder->canCancel()) {
            return back()->withErrors(['error' => 'This purchase order cannot be cancelled.']);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ]);

        $purchaseOrder->update([
            'status' => 'cancelled',
            'notes' => ($purchaseOrder->notes ?? '') . "\n\nCancellation Reason: " . $validated['cancellation_reason'],
        ]);

        return back()->with('success', 'Purchase Order cancelled successfully.');
    }

    /**
     * Delete purchase order (only draft)
     */
    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->withErrors(['error' => 'Only draft purchase orders can be deleted.']);
        }

        $orderId = $purchaseOrder->order_number;
        $purchaseOrder->delete();

        return redirect()->route('admin.purchase-orders.index')
            ->with('success', "Purchase Order {$orderId} deleted successfully.");
    }

    /**
     * Get current financial year
     */
    private function getCurrentFinancialYear(): string
    {
        $now = now();
        $year = $now->year;
        
        if ($now->month >= 4) { // April onwards
            return ($year) . '-' . ($year + 1 - 2000);
        } else {
            return ($year - 1) . '-' . ($year - 2000);
        }
    }

    /**
     * Export purchase orders to CSV
     */
    public function export(Request $request)
    {
        $query = PurchaseOrder::with('supplier');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $purchaseOrders = $query->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="purchase_orders_' . now()->format('Y-m-d') . '.csv"',
        ];

        $callback = function() use ($purchaseOrders) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Order Number', 'Supplier', 'Order Date', 'Status', 'Total Amount', 'Created By']);

            foreach ($purchaseOrders as $po) {
                fputcsv($file, [
                    $po->order_number,
                    $po->supplier?->name ?? 'Unknown',
                    $po->order_date->format('Y-m-d'),
                    ucfirst($po->status),
                    $po->total_amount,
                    $po->createdBy?->name ?? 'System',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
