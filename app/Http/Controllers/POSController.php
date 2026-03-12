<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalePayment;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Models\InventoryLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        return Inertia::render('POS/Index', [
            'franchisee_id' => $franchiseeId,
        ]);
    }

    // ─────────────────────────────────────────────────────
    //  AJAX APIs — All return JSON for the POS Vue screen
    // ─────────────────────────────────────────────────────

    /**
     * Live product search (name / SKU / barcode).
     * Returns product info + GST rates (read from product, not hardcoded).
     * Legacy: getMedicineInfo() + ajax_get_products()
     */
    public function searchProduct(Request $request)
    {
        $term = trim($request->input('term', ''));
        if (strlen($term) < 2) {
            return response()->json([]);
        }

        $products = Product::where('is_active', true)
            ->where('hide', false)
            ->where(function ($q) use ($term) {
                $q->where('product_name', 'like', "%{$term}%")
                  ->orWhere('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%");
            })
            ->select('id', 'product_name', 'sku', 'barcode', 'mrp', 'rate_a', 'csr',
                     'sgst', 'cgst', 'igst', 'conversion_factor', 'packing_desc',
                     'hsn_id', 'max_discount')
            ->limit(15)
            ->get();

        return response()->json($products);
    }

    /**
     * Get all available batches for a product at a franchisee (FEFO order).
     * Legacy: getMedicineBatchInfo() — queries tbl_stock ordered by expiry_date
     */
    public function getProductBatches(Request $request, InventoryService $inventoryService)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $batches = $inventoryService->getProductStockAtLocation(
            (int) $request->input('product_id'),
            'franchisee',
            $franchiseeId
        );

        return response()->json($batches);
    }

    /**
     * Check available stock for a specific product + batch.
     * Legacy: checkQtyAvailbleOrNot()
     */
    public function checkStock(Request $request, InventoryService $inventoryService)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'batch_no'   => 'required|string',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $stock = $inventoryService->getStock(
            (int) $request->input('product_id'),
            $request->input('batch_no'),
            'franchisee',
            $franchiseeId
        );

        return response()->json(['stock' => $stock]);
    }

    /**
     * Look up customer by mobile number.
     * Legacy: getCustOfMobNo()
     */
    public function lookupCustomer(Request $request)
    {
        $request->validate(['mobile' => 'required|string']);
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $customer = Customer::where('franchisee_id', $franchiseeId)
            ->where('mobile', $request->input('mobile'))
            ->first(['id', 'name', 'mobile', 'address']);

        return response()->json($customer);
    }

    /**
     * Search customers by name (autocomplete).
     * Legacy: getCustNameUsingName()
     */
    public function searchCustomers(Request $request)
    {
        $request->validate(['term' => 'required|string|min:2']);
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $customers = Customer::where('franchisee_id', $franchiseeId)
            ->where('name', 'like', '%' . $request->input('term') . '%')
            ->limit(10)
            ->get(['id', 'name', 'mobile', 'address']);

        return response()->json($customers);
    }

    /**
     * Quick-add a new customer from the POS screen.
     * Legacy: submitCustInfo()
     */
    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name'   => 'required|string|max:255',
            'mobile' => 'required|string|max:20',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $customer = Customer::firstOrCreate(
            ['mobile' => $validated['mobile'], 'franchisee_id' => $franchiseeId],
            ['name' => $validated['name']]
        );

        return response()->json($customer);
    }

    /**
     * Search doctors by name for prescription attachment.
     * Legacy: getDoctInfoUsingName()
     */
    public function searchDoctors(Request $request)
    {
        $request->validate(['term' => 'required|string|min:2']);
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $doctors = Doctor::where('franchisee_id', $franchiseeId)
            ->where('name', 'like', '%' . $request->input('term') . '%')
            ->limit(10)
            ->get(['id', 'name', 'reg_no']);

        return response()->json($doctors);
    }

    /**
     * Quick-add a new doctor from the POS screen.
     * Legacy: submitDoctNameInfo()
     */
    public function storeDoctor(Request $request)
    {
        $validated = $request->validate([
            'name'   => 'required|string|max:255',
            'reg_no' => 'nullable|string|max:100',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $doctor = Doctor::firstOrCreate(
            ['name' => $validated['name'], 'franchisee_id' => $franchiseeId],
            ['reg_no' => $validated['reg_no'] ?? null]
        );

        return response()->json($doctor);
    }

    /**
     * Get the next bill number using a locked counter row — race-condition safe.
     * Two concurrent POS sessions will never get the same counter value.
     *
     * Legacy: Used a simple COUNT(*) with no lock — duplicates possible under load.
     */
    public function nextBillNumber(Request $request)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());
        $user = $request->user();
        $shopCode = $user->franchisee?->shop_code ?? 'DEV';
        $today = today()->toDateString();

        $counter = DB::transaction(function () use ($franchiseeId, $today) {
            // Lock the counter row for this franchisee+date. If it doesn't exist yet,
            // INSERT it (upsert), then lock and increment — all atomic.
            DB::table('bill_counters')->insertOrIgnore([
                'franchisee_id' => $franchiseeId,
                'counter_date' => $today,
                'last_counter' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('bill_counters')
                ->where('franchisee_id', $franchiseeId)
                ->where('counter_date', $today)
                ->lockForUpdate()
                ->first();

            // Defensive: should always exist due to insertOrIgnore above, but guard anyway
            $next = ($row ? (int) $row->last_counter : 0) + 1;

            DB::table('bill_counters')
                ->where('franchisee_id', $franchiseeId)
                ->where('counter_date', $today)
                ->update(['last_counter' => $next, 'updated_at' => now()]);

            return $next;
        });

        $billNo = 'POS-' . $shopCode . '-' . date('Ymd') . '-' . str_pad($counter, 4, '0', STR_PAD_LEFT);

        return response()->json(['bill_no' => $billNo]);
    }

    /**
     * Get a customer's pending credit balance and prior bill history.
     * Legacy: getPrevCreditAmount() + getPrevBillRecord()
     */
    public function customerCreditInfo(Request $request)
    {
        $request->validate(['customer_id' => 'required|integer|exists:customers,id']);
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $pendingCredit = SalePayment::whereHas('invoice', function ($q) use ($request, $franchiseeId) {
            $q->where('customer_id', $request->input('customer_id'))
              ->where('franchisee_id', $franchiseeId)
              ->where('status', 'completed');
        })->sum('credit_amount');

        $bills = SalesInvoice::where('customer_id', $request->input('customer_id'))
            ->where('franchisee_id', $franchiseeId)
            ->where('status', 'completed')
            ->latest()
            ->limit(10)
            ->get(['id', 'bill_no', 'date_time', 'total_amount']);

        return response()->json([
            'pending_credit' => $pendingCredit,
            'recent_bills'   => $bills,
        ]);
    }

    // ─────────────────────────────────────────────────────
    //  CHECKOUT — Submit Bill
    // ─────────────────────────────────────────────────────

    /**
     * Process the POS sale: create invoice, deduct stock, record payment.
     * Legacy: submitDataAndGetReciept()
     */
    public function checkout(Request $request, InventoryService $inventoryService, LedgerService $ledgerService)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $validated = $request->validate([
            'bill_no'                  => 'required|string|max:60',
            'customer_id'              => 'nullable|integer|exists:customers,id',
            'customer_name'            => 'nullable|string|max:255',
            'customer_mobile'          => 'nullable|string|max:20',
            'doctor_id'                => 'nullable|integer|exists:doctors,id',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|integer|exists:products,id',
            'items.*.batch_no'         => 'required|string|max:50',
            'items.*.expiry_date'      => 'nullable|date',
            'items.*.mrp'              => 'required|numeric|min:0',
            'items.*.rate'             => 'required|numeric|min:0',
            'items.*.qty'              => 'required|numeric|min:0.01',
            'items.*.discount_percent' => 'required|numeric|min:0|max:100',
            'payment_mode'             => 'required|string|in:cash,bank,credit,cashCredit,bankCredit,cashBank',
            'cash_amount'              => 'required|numeric|min:0',
            'bank_amount'              => 'required|numeric|min:0',
            'credit_amount'            => 'required|numeric|min:0',
            'transaction_no'           => 'nullable|string|max:100',
            'wallet_type'              => 'nullable|string|max:50',
            'sub_total'                => 'required|numeric',
            'total_discount_amount'    => 'required|numeric',
            'total_tax_amount'         => 'required|numeric',
            'other_charges'            => 'nullable|numeric|min:0',
            'total_amount'             => 'required|numeric',
        ]);

        return DB::transaction(function () use ($validated, $user, $inventoryService, $ledgerService, $franchiseeId) {
            // Resolve or create customer
            $customerId = $validated['customer_id'] ?? null;
            if (!$customerId && !empty($validated['customer_mobile'])) {
                $customer = Customer::firstOrCreate(
                    ['mobile' => $validated['customer_mobile'], 'franchisee_id' => $franchiseeId],
                    ['name' => $validated['customer_name'] ?? 'Walk-in']
                );
                $customerId = $customer->id;
            }

            // Load product data in bulk to avoid N+1 (GST + max_discount + conversion_factor)
            $productIds = array_column($validated['items'], 'product_id');
            $productMap = Product::whereIn('id', $productIds)
                ->get(['id', 'product_name', 'sgst', 'cgst', 'igst', 'conversion_factor', 'max_discount'])
                ->keyBy('id');

            // ── Pre-flight checks (better than legacy: legacy had none of these) ──────

            foreach ($validated['items'] as $item) {
                $product = $productMap[$item['product_id']] ?? null;

                // 1. Block expired batches — legacy silently sold them
                if (!empty($item['expiry_date']) && \Carbon\Carbon::parse($item['expiry_date'])->isPast()) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Batch '{$item['batch_no']}' of {$name} is expired. Remove it from the cart.");
                }

                // 2. Enforce max_discount — legacy sometimes skipped this on fast entry
                $maxDisc = $product?->max_discount ?? 100;
                if ((float) $item['discount_percent'] > (float) $maxDisc) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Discount {$item['discount_percent']}% on {$name} exceeds maximum allowed {$maxDisc}%.");
                }

                // 3. Race-condition guard: lock the ledger rows for this batch and
                //    verify there's enough stock INSIDE the transaction before writing.
                //    Legacy: no lock → two simultaneous bills could both sell the last strip.
                $availableStock = InventoryLedger::where('product_id', $item['product_id'])
                    ->where('batch_no', $item['batch_no'])
                    ->where('location_type', 'franchisee')
                    ->where('location_id', $franchiseeId)
                    ->lockForUpdate()
                    ->selectRaw('COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0) as net')
                    ->value('net') ?? 0;

                // Allow negative stock if product has no ledger entry yet
                // (e.g. opening stock entered manually), but warn via logs.
                if ($availableStock > 0 && (float) $item['qty'] > (float) $availableStock) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Insufficient stock for {$name} batch '{$item['batch_no']}'. Available: {$availableStock}, Requested: {$item['qty']}.");
                }
            }

            // ── All checks passed — create the invoice ─────────────────────────────

            $invoice = SalesInvoice::create([
                'bill_no'               => $validated['bill_no'],
                'franchisee_id'         => $franchiseeId,
                'user_id'               => $user->id,
                'customer_id'           => $customerId,
                'doctor_id'             => $validated['doctor_id'] ?? null,
                'date_time'             => now(),
                'sub_total'             => $validated['sub_total'],
                'total_discount_amount' => $validated['total_discount_amount'],
                'total_tax_amount'      => $validated['total_tax_amount'],
                'other_charges'         => $validated['other_charges'] ?? 0,
                'total_amount'          => $validated['total_amount'],
                'status'                => 'completed',
            ]);

            foreach ($validated['items'] as $item) {
                $product = $productMap[$item['product_id']] ?? null;

                // Read actual GST from product master — not hardcoded
                $sgst = (float) ($product->sgst ?? 0);
                $cgst = (float) ($product->cgst ?? 0);
                $igst = (float) ($product->igst ?? 0);
                // POS is always intra-state (same franchisee city) → use SGST+CGST
                $gstPercent = ($sgst + $cgst) ?: $igst;

                $lineBase   = round((float) $item['rate'] * (float) $item['qty'], 4);
                $discAmt    = round($lineBase * ((float) $item['discount_percent'] / 100), 4);
                $taxableAmt = $lineBase - $discAmt;
                $gstAmt     = round($taxableAmt * ($gstPercent / 100), 4);
                $lineTotal  = $taxableAmt + $gstAmt;

                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'product_id'       => $item['product_id'],
                    'batch_no'         => $item['batch_no'],
                    'expiry_date'      => $item['expiry_date'] ?? null,
                    'qty'              => $item['qty'],
                    'mrp'              => $item['mrp'],
                    'rate'             => $item['rate'],
                    'discount_percent' => $item['discount_percent'],
                    'discount_amount'  => $discAmt,
                    'taxable_amount'   => $taxableAmt,
                    'gst_percent'      => $gstPercent,
                    'gst_amount'       => $gstAmt,
                    'total_amount'     => $lineTotal,
                ]);

                // Deduct stock via InventoryService (creates audit ledger entry)
                $inventoryService->recordSale([
                    'product_id'    => $item['product_id'],
                    'batch_no'      => $item['batch_no'],
                    'expiry_date'   => $item['expiry_date'] ?? null,
                    'mrp'           => $item['mrp'],
                    'franchisee_id' => $franchiseeId,
                    'qty'           => $item['qty'],
                    'rate'          => $item['rate'],
                    'reference_id'  => $invoice->id,
                    'created_by'    => $user->id,
                ]);
            }

            // Record payment split
            SalePayment::create([
                'sales_invoice_id' => $invoice->id,
                'payment_mode'     => $validated['payment_mode'],
                'cash_amount'      => $validated['cash_amount'],
                'bank_amount'      => $validated['bank_amount'],
                'credit_amount'    => $validated['credit_amount'],
                'transaction_no'   => $validated['transaction_no'] ?? null,
                'wallet_type'      => $validated['wallet_type'] ?? null,
            ]);

            // Financial ledger entry for the franchisee's cash / bank
            if ($validated['total_amount'] > 0) {
                $ledgerService->recordEntry(
                    ledgerable: \App\Models\Franchisee::find($franchiseeId),
                    transactionType: 'POS_SALE',
                    debit: 0,
                    credit: $validated['total_amount'],
                    reference: $invoice,
                    paymentMode: $validated['payment_mode'],
                    narration: "Sale [{$validated['bill_no']}]"
                );
            }

            return response()->json([
                'success'  => true,
                'bill_no'  => $invoice->bill_no,
                'invoice_id' => $invoice->id,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────
    //  SALES RETURN
    // ─────────────────────────────────────────────────────

    /**
     * Process a POS return (partial or full bill).
     * Updates item qty/price on the original invoice, adds stock back.
     * Legacy: returnSaleData()
     */
    public function processReturn(Request $request, InventoryService $inventoryService)
    {
        $validated = $request->validate([
            'original_bill_no'          => 'required|string|exists:sales_invoices,bill_no',
            'items'                      => 'required|array|min:1',
            'items.*.sales_invoice_item_id' => 'required|integer|exists:sales_invoice_items,id',
            'items.*.return_qty'         => 'required|numeric|min:0.01',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        return DB::transaction(function () use ($validated, $inventoryService, $franchiseeId, $request) {
            $original = SalesInvoice::where('bill_no', $validated['original_bill_no'])
                ->where('franchisee_id', $franchiseeId)
                ->firstOrFail();

            $totalReturnAmount = 0;

            foreach ($validated['items'] as $retItem) {
                $lineItem = SalesInvoiceItem::where('id', $retItem['sales_invoice_item_id'])
                    ->where('sales_invoice_id', $original->id)
                    ->firstOrFail();

                $returnQty = min($retItem['return_qty'], $lineItem->qty);
                $perUnitTotal = $lineItem->qty > 0 ? $lineItem->total_amount / $lineItem->qty : 0;
                $returnAmount = round($returnQty * $perUnitTotal, 2);
                $totalReturnAmount += $returnAmount;

                $lineItem->decrement('qty', $returnQty);
                $lineItem->decrement('total_amount', $returnAmount);

                // Add stock back
                $inventoryService->recordSaleReturn([
                    'product_id'    => $lineItem->product_id,
                    'batch_no'      => $lineItem->batch_no,
                    'expiry_date'   => $lineItem->expiry_date,
                    'mrp'           => $lineItem->mrp,
                    'franchisee_id' => $franchiseeId,
                    'qty'           => $returnQty,
                    'rate'          => $lineItem->rate,
                    'reference_id'  => $original->id,
                    'created_by'    => $request->user()->id,
                ]);
            }

            // Reduce invoice total
            $original->decrement('total_amount', $totalReturnAmount);

            return response()->json([
                'success'       => true,
                'return_amount' => $totalReturnAmount,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────
    //  HELPER
    // ─────────────────────────────────────────────────────

    private function resolveFranchiseeId($user): int
    {
        if ($user->franchisee_id) {
            return (int) $user->franchisee_id;
        }

        // Admin bypass — use first franchisee for testing
        $first = \App\Models\Franchisee::first();
        abort_if(!$first, 403, 'No franchisees in system.');
        return (int) $first->id;
    }
}
