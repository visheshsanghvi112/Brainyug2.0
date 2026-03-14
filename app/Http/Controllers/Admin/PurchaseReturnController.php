<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private LedgerService $ledgerService
    ) {}

    public function index(Request $request)
    {
        $returns = PurchaseReturn::with(['supplier', 'purchaseInvoice', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('return_number', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Procurement/PurchaseReturns/Index', [
            'returns' => $returns,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Procurement/PurchaseReturns/CreateEdit', [
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'invoices' => PurchaseInvoice::approved()->latest()->take(50)->get(['id', 'invoice_number', 'supplier_id']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|exists:purchase_invoices,id',
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

        $linkedInvoice = null;
        if (!empty($validated['purchase_invoice_id'])) {
            $linkedInvoice = PurchaseInvoice::with('items')->findOrFail($validated['purchase_invoice_id']);

            if ((int) $linkedInvoice->supplier_id !== (int) $validated['supplier_id']) {
                return back()->withErrors([
                    'purchase_invoice_id' => 'Selected invoice belongs to a different supplier. Please choose a matching invoice.',
                ])->withInput();
            }

            $this->validateInvoiceLinkedReturnItems($linkedInvoice, $validated['items']);
        }

        DB::transaction(function () use ($validated, $request, $linkedInvoice) {
            // Generate return number
            $lastReturn = PurchaseReturn::where('financial_year', PurchaseInvoice::currentFinancialYear())
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastReturn ? ((int) substr($lastReturn->return_number, -4)) + 1 : 1;
            $returnNumber = 'PR-' . PurchaseInvoice::currentFinancialYear() . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            $subtotal = 0;
            $totalSgst = 0;
            $totalCgst = 0;
            $totalIgst = 0;
            $itemsData = [];

            // We assume intra-state if no invoice linked, or we could fetch supplier state vs HO state.
            // For simplicity, if linked to invoice, use its tax type, else default intra.
            $taxType = 'intra_state';
            if ($linkedInvoice) {
                $taxType = $linkedInvoice->tax_type;
            }

            $invoiceLineMap = $linkedInvoice
                ? $linkedInvoice->items->groupBy(fn($i) => $i->product_id . '|' . $i->batch_no)
                : collect();

            foreach ($validated['items'] as $item) {
                $qty = (float) $item['qty'];
                $rate = (float) $item['rate'];
                $gstPct = (float) $item['gst_percent'];
                $expiryDate = $item['expiry_date'] ?? null;

                if ($linkedInvoice) {
                    $key = $item['product_id'] . '|' . $item['batch_no'];
                    $invoiceLines = $invoiceLineMap->get($key);

                    if (!$invoiceLines || $invoiceLines->isEmpty()) {
                        throw ValidationException::withMessages([
                            'items' => "Return item product {$item['product_id']} batch {$item['batch_no']} does not exist on linked invoice {$linkedInvoice->invoice_number}.",
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
                if (!$this->inventoryService->hasSufficientStock(
                    $item['product_id'], $item['batch_no'], 'warehouse', 0, $qty
                )) {
                    throw new \Exception("Insufficient stock in warehouse for product ID {$item['product_id']}, batch {$item['batch_no']}. Cannot return {$qty}.");
                }

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
                'purchase_invoice_id' => $validated['purchase_invoice_id'] ?? null,
                'return_date' => $validated['return_date'],
                'financial_year' => PurchaseInvoice::currentFinancialYear(),
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
        });

        return redirect()->route('admin.purchase-returns.index')
            ->with('success', 'Purchase return created as draft.');
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseInvoice', 'items.product', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseReturns/Show', [
            'purchaseReturn' => $purchaseReturn,
        ]);
    }

    /**
     * APPROVE a purchase return → auto-create inventory ledger entries (DEDUCT STOCK).
     */
    public function approve(Request $request, PurchaseReturn $purchaseReturn)
    {
        try {
            DB::transaction(function () use ($purchaseReturn, $request) {
                $actor = $request->user();

                $lockedReturn = PurchaseReturn::whereKey($purchaseReturn->id)
                    ->lockForUpdate()
                    ->with(['items', 'supplier', 'purchaseInvoice.items'])
                    ->firstOrFail();

                if ($lockedReturn->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'status' => 'Only draft returns can be approved.',
                    ]);
                }

                // Maker-checker: creator cannot approve own return unless Super Admin.
                if ((int) $lockedReturn->created_by === (int) $actor->id && !$actor->isSuperAdmin()) {
                    throw ValidationException::withMessages([
                        'approval' => 'Maker-checker rule: the creator cannot approve this return. Ask another authorized user to approve.',
                    ]);
                }

                // Re-validate linked invoice constraints at approval time to prevent race conditions.
                if ($lockedReturn->purchaseInvoice) {
                    $returnItemsPayload = $lockedReturn->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'batch_no' => $item->batch_no,
                            'qty' => (float) $item->qty,
                        ];
                    })->all();

                    $this->validateInvoiceLinkedReturnItems(
                        $lockedReturn->purchaseInvoice,
                        $returnItemsPayload,
                        $lockedReturn->id
                    );
                }

                $lockedReturn->update([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                ]);

                // Create inventory ledger entries for each line item
                foreach ($lockedReturn->items as $item) {
                    // Re-check stock at approval time to avoid overselling during concurrent operations.
                    if (!$this->inventoryService->hasSufficientStock(
                        $item->product_id,
                        $item->batch_no,
                        'warehouse',
                        0,
                        (float) $item->qty
                    )) {
                        throw ValidationException::withMessages([
                            'items' => "Insufficient stock at approval for product ID {$item->product_id}, batch {$item->batch_no}.",
                        ]);
                    }

                    $this->inventoryService->recordPurchaseReturn([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'expiry_date' => $item->expiry_date,
                        'qty' => $item->qty,
                        'rate' => $item->rate,
                        'reference_id' => $lockedReturn->id,
                        'created_by' => $actor->id,
                    ]);
                }

                $this->ledgerService->recordEntry(
                    $lockedReturn->supplier,
                    'PURCHASE_RETURN',
                    debit: (float) $lockedReturn->total_amount,
                    credit: 0,
                    reference: $lockedReturn,
                    paymentMode: 'adjustment',
                    narration: "Purchase return {$lockedReturn->return_number} approved against supplier {$lockedReturn->supplier->name}",
                    transactionDate: $lockedReturn->return_date,
                );
            });
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
            DB::transaction(function () use ($purchaseReturn) {
                $lockedReturn = PurchaseReturn::whereKey($purchaseReturn->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedReturn->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        'status' => 'Return is already cancelled.',
                    ]);
                }

                if ($lockedReturn->status === 'approved') {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot cancel an approved return directly. Reverse it manually.',
                    ]);
                }

                $lockedReturn->update(['status' => 'cancelled']);
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Return cancelled.');
    }

    /**
     * Validate invoice-linked return lines do not exceed purchased quantities for each product+batch.
     */
    private function validateInvoiceLinkedReturnItems(PurchaseInvoice $invoice, array $items, ?int $excludingReturnId = null): void
    {
        $purchasedByKey = $invoice->items
            ->groupBy(fn($i) => $i->product_id . '|' . $i->batch_no)
            ->map(fn($group) => (float) $group->sum(fn($i) => (float) $i->qty + (float) $i->free_qty));

        $alreadyReturnedByKey = PurchaseReturnItem::query()
            ->selectRaw('purchase_return_items.product_id, purchase_return_items.batch_no, COALESCE(SUM(purchase_return_items.qty), 0) as returned_qty')
            ->join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('pr.purchase_invoice_id', $invoice->id)
            ->where('pr.status', 'approved')
            ->when($excludingReturnId, fn($q) => $q->where('pr.id', '!=', $excludingReturnId))
            ->groupBy('purchase_return_items.product_id', 'purchase_return_items.batch_no')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->product_id . '|' . $row->batch_no => (float) $row->returned_qty,
            ]);

        $requestedByKey = collect($items)
            ->groupBy(fn($i) => $i['product_id'] . '|' . $i['batch_no'])
            ->map(fn($group) => (float) $group->sum(fn($i) => (float) $i['qty']));

        $errors = [];

        foreach ($requestedByKey as $key => $requestedQty) {
            $purchasedQty = (float) ($purchasedByKey[$key] ?? 0);
            $returnedQty = (float) ($alreadyReturnedByKey[$key] ?? 0);

            if ($purchasedQty <= 0) {
                [$productId, $batchNo] = explode('|', $key);
                $errors[] = "Item product {$productId}, batch {$batchNo} does not exist on linked invoice {$invoice->invoice_number}.";
                continue;
            }

            if (($requestedQty + $returnedQty) > $purchasedQty + 0.0001) {
                [$productId, $batchNo] = explode('|', $key);
                $errors[] = "Return qty exceeds purchased qty for product {$productId}, batch {$batchNo}. Purchased: {$purchasedQty}, already returned: {$returnedQty}, requested: {$requestedQty}.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages([
                'items' => implode(' ', $errors),
            ]);
        }
    }

    /**
     * Export purchase returns to CSV.
     */
    public function export(Request $request)
    {
        $query = PurchaseReturn::with(['supplier', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('return_number', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest();

        $returns = $query->get();

        $filename = 'purchase_returns_' . date('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = [
            'Return No', 'Date', 'Supplier', 'Status', 'Total Amount',
            'CGST', 'SGST', 'IGST', 'Created By',
        ];

        $callback = function () use ($returns, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($returns as $return) {
                fputcsv($file, [
                    $return->return_number,
                    optional($return->return_date)?->format('Y-m-d'),
                    $return->supplier->name ?? '',
                    ucfirst($return->status),
                    $return->total_amount,
                    $return->cgst_amount,
                    $return->sgst_amount,
                    $return->igst_amount,
                    $return->createdBy->name ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Print view for a purchase return.
     */
    public function print(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseInvoice', 'items.product', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseReturns/Show', [
            'purchaseReturn' => $purchaseReturn,
        ]);
    }
}
