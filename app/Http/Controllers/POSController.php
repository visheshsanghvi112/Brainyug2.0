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
use App\Models\CustomerCreditCollection;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Models\Franchisee;
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

        $products = Product::visibleForFranchise()
            ->with('hsn:id,cgst_percent,sgst_percent,igst_percent')
            ->searchByTerm($term)
            ->select('id', 'product_name', 'sku', 'barcode', 'mrp', 'rate_a', 'csr',
                     'sgst', 'cgst', 'igst', 'conversion_factor', 'packing_desc',
                     'hsn_id', 'max_discount', 'free_schema', 'product_code', 'fast_search_index', 'ptr', 'pts')
            ->limit(15)
            ->get()
            ->map(function (Product $product) {
                $product->rate_a = $product->franchiseRate();
                $product->sgst = (float) (($product->sgst ?? 0) ?: ($product->hsn?->sgst_percent ?? 0));
                $product->cgst = (float) (($product->cgst ?? 0) ?: ($product->hsn?->cgst_percent ?? 0));
                $product->igst = (float) (($product->igst ?? 0) ?: ($product->hsn?->igst_percent ?? 0));
                $product->gst_percent = $product->gstPercent();

                return $product;
            })
            ->values();

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

        Product::query()
            ->visibleForFranchise()
            ->whereKey((int) $request->input('product_id'))
            ->firstOrFail();

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

        Product::query()
            ->visibleForFranchise()
            ->whereKey((int) $request->input('product_id'))
            ->firstOrFail();

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

        $customer = Customer::where('id', $request->integer('customer_id'))
            ->where('franchisee_id', $franchiseeId)
            ->firstOrFail(['id', 'name', 'mobile']);

        $snapshot = $this->customerCreditSnapshot($customer->id, $franchiseeId, limit: 10);

        return response()->json([
            'customer' => $customer,
            'pending_credit' => $snapshot['pending_credit'],
            'recent_bills'   => $snapshot['recent_bills'],
            'recent_collections' => $snapshot['recent_collections'],
        ]);
    }

    /**
     * Collect outstanding customer credit and allocate against oldest open invoices.
     */
    public function collectCredit(Request $request, LedgerService $ledgerService)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_mode' => 'required|string|in:cash,bank,upi,card,cheque,neft,rtgs',
            'payment_date' => 'required|date',
            'transaction_no' => 'nullable|string|max:100',
            'wallet_type' => 'nullable|string|max:50',
            'narration' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $customer = Customer::where('id', $validated['customer_id'])
            ->where('franchisee_id', $franchiseeId)
            ->firstOrFail(['id', 'name']);

        $requestedAmount = round((float) $validated['amount'], 2);

        $result = DB::transaction(function () use ($customer, $franchiseeId, $requestedAmount, $validated, $user, $ledgerService) {
            $openInvoices = SalesInvoice::query()
                ->where('franchisee_id', $franchiseeId)
                ->where('customer_id', $customer->id)
                ->where('status', 'completed')
                ->orderBy('date_time')
                ->lockForUpdate()
                ->get(['id', 'bill_no', 'date_time']);

            $remaining = $requestedAmount;
            $allocations = [];
            $firstCollection = null;
            $totalOutstanding = 0.0;

            foreach ($openInvoices as $invoice) {
                $raised = (float) SalePayment::query()
                    ->where('sales_invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->sum('credit_amount');

                if ($raised <= 0) {
                    continue;
                }

                $collected = (float) CustomerCreditCollection::query()
                    ->where('sales_invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->sum('amount');

                $outstanding = round(max(0, $raised - $collected), 2);
                $totalOutstanding += $outstanding;

                if ($outstanding <= 0 || $remaining <= 0) {
                    continue;
                }

                $allocation = round(min($remaining, $outstanding), 2);

                $entry = CustomerCreditCollection::create([
                    'franchisee_id' => $franchiseeId,
                    'customer_id' => $customer->id,
                    'sales_invoice_id' => $invoice->id,
                    'amount' => $allocation,
                    'payment_mode' => $validated['payment_mode'],
                    'transaction_no' => $validated['transaction_no'] ?? null,
                    'wallet_type' => $validated['wallet_type'] ?? null,
                    'narration' => $validated['narration'] ?? null,
                    'collected_at' => $validated['payment_date'],
                    'created_by' => $user->id,
                ]);

                if (!$firstCollection) {
                    $firstCollection = $entry;
                }

                $allocations[] = [
                    'invoice_id' => $invoice->id,
                    'bill_no' => $invoice->bill_no,
                    'allocated' => $allocation,
                ];

                $remaining = round($remaining - $allocation, 2);
            }

            if ($totalOutstanding <= 0) {
                abort(422, 'This customer has no outstanding credit to collect.');
            }

            if ($remaining > 0) {
                abort(422, "Collection exceeds outstanding credit. Available outstanding is {$totalOutstanding}.");
            }

            $franchisee = Franchisee::query()->findOrFail($franchiseeId);

            $ledgerService->recordEntry(
                ledgerable: $franchisee,
                transactionType: 'PAYMENT_RECEIVED',
                debit: 0,
                credit: $requestedAmount,
                reference: $firstCollection,
                paymentMode: $validated['payment_mode'],
                narration: $validated['narration']
                    ? "Credit collection from {$customer->name}: {$validated['narration']}"
                    : "Credit collection from {$customer->name}",
                transactionDate: $validated['payment_date'],
            );

            return [
                'allocated_total' => $requestedAmount,
                'allocations' => $allocations,
            ];
        });

        $snapshot = $this->customerCreditSnapshot($customer->id, $franchiseeId, limit: 10);

        return response()->json([
            'success' => true,
            'message' => 'Customer credit collected successfully.',
            'allocated_total' => $result['allocated_total'],
            'allocations' => $result['allocations'],
            'pending_credit' => $snapshot['pending_credit'],
            'recent_bills' => $snapshot['recent_bills'],
            'recent_collections' => $snapshot['recent_collections'],
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
            'items.*.free_qty'         => 'nullable|numeric|min:0',
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
            $productMap = Product::query()
                ->with('hsn:id,cgst_percent,sgst_percent,igst_percent')
                ->whereIn('id', $productIds)
                ->visibleForFranchise()
                ->get(['id', 'product_name', 'product_code', 'mrp', 'rate_a', 'ptr', 'pts', 'sgst', 'cgst', 'igst', 'hsn_id', 'conversion_factor', 'max_discount'])
                ->keyBy('id');

            if ($productMap->count() !== count(array_unique($productIds))) {
                abort(422, 'One or more products in this bill are no longer available for franchise sale. Please refresh the cart.');
            }

            // ── Pre-flight checks (better than legacy: legacy had none of these) ──────

            foreach ($validated['items'] as $item) {
                $product = $productMap[$item['product_id']] ?? null;
                $masterRate = $product?->franchiseRate() ?? 0;
                $masterMrp = round((float) ($product?->mrp ?? 0), 2);

                // 1. Block expired batches — legacy silently sold them
                if (!empty($item['expiry_date']) && \Carbon\Carbon::parse($item['expiry_date'])->isPast()) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Batch '{$item['batch_no']}' of {$name} is expired. Remove it from the cart.");
                }

                if (abs((float) $item['rate'] - $masterRate) > 0.01) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Rate changed for {$name}. Latest rate is {$masterRate}. Please refresh and bill again.");
                }

                if (abs((float) $item['mrp'] - $masterMrp) > 0.01) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "MRP changed for {$name}. Latest MRP is {$masterMrp}. Please refresh and bill again.");
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
                $requestedQty = (float) $item['qty'] + (float) ($item['free_qty'] ?? 0);
                if ($availableStock > 0 && $requestedQty > (float) $availableStock) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Insufficient stock for {$name} batch '{$item['batch_no']}'. Available: {$availableStock}, Requested: {$requestedQty} (incl. free quantity).");
                }
            }

            // ── All checks passed — create the invoice ─────────────────────────────

            $linePayloads = [];
            $summarySubTotal = 0.0;
            $summaryDiscount = 0.0;
            $summaryTax = 0.0;

            foreach ($validated['items'] as $item) {
                $product = $productMap[$item['product_id']] ?? null;
                $masterRate = $product?->franchiseRate() ?? 0;
                $masterMrp = round((float) ($product?->mrp ?? 0), 2);

                $gstPercent = $product?->gstPercent() ?? 0;

                $lineBase   = round($masterRate * (float) $item['qty'], 4);
                $discAmt    = round($lineBase * ((float) $item['discount_percent'] / 100), 4);
                $taxableAmt = $lineBase - $discAmt;
                $gstAmt     = round($taxableAmt * ($gstPercent / 100), 4);
                $lineTotal  = $taxableAmt + $gstAmt;

                $summarySubTotal += $lineBase;
                $summaryDiscount += $discAmt;
                $summaryTax += $gstAmt;

                $linePayloads[] = [
                    'product_id' => $item['product_id'],
                    'batch_no' => $item['batch_no'],
                    'exp_date' => $item['expiry_date'] ?? null,
                    'qty' => $item['qty'],
                    'free_qty' => $item['free_qty'] ?? 0,
                    'mrp' => $masterMrp,
                    'rate' => $masterRate,
                    'discount_percent' => $item['discount_percent'],
                    'discount_amount' => $discAmt,
                    'taxable_amount' => $taxableAmt,
                    'gst_percent' => $gstPercent,
                    'gst_amount' => $gstAmt,
                    'total_amount' => $lineTotal,
                    'inventory_expiry_date' => $item['expiry_date'] ?? null,
                ];
            }

            $otherCharges = round((float) ($validated['other_charges'] ?? 0), 2);
            $grossTotal = round(($summarySubTotal - $summaryDiscount) + $summaryTax + $otherCharges, 2);
            $invoiceTotal = round($grossTotal, 0);

            $invoice = SalesInvoice::create([
                'bill_no' => $validated['bill_no'],
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'doctor_id' => $validated['doctor_id'] ?? null,
                'date_time' => now(),
                'sub_total' => round($summarySubTotal, 2),
                'total_discount_amount' => round($summaryDiscount, 2),
                'total_tax_amount' => round($summaryTax, 2),
                'other_charges' => $otherCharges,
                'total_amount' => $invoiceTotal,
                'status' => 'completed',
            ]);

            foreach ($linePayloads as $linePayload) {
                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'product_id' => $linePayload['product_id'],
                    'batch_no' => $linePayload['batch_no'],
                    'exp_date' => $linePayload['exp_date'],
                    'qty' => $linePayload['qty'],
                    'free_qty' => $linePayload['free_qty'],
                    'mrp' => $linePayload['mrp'],
                    'rate' => $linePayload['rate'],
                    'discount_percent' => $linePayload['discount_percent'],
                    'discount_amount' => $linePayload['discount_amount'],
                    'taxable_amount' => $linePayload['taxable_amount'],
                    'gst_percent' => $linePayload['gst_percent'],
                    'gst_amount' => $linePayload['gst_amount'],
                    'total_amount' => $linePayload['total_amount'],
                ]);

                // Deduct stock via InventoryService (creates audit ledger entry)
                $inventoryService->recordSale([
                    'product_id' => $linePayload['product_id'],
                    'batch_no' => $linePayload['batch_no'],
                    'expiry_date' => $linePayload['inventory_expiry_date'],
                    'mrp' => $linePayload['mrp'],
                    'franchisee_id' => $franchiseeId,
                    'qty' => (float) $linePayload['qty'] + (float) $linePayload['free_qty'],
                    'rate' => $linePayload['rate'],
                    'reference_id' => $invoice->id,
                    'created_by' => $user->id,
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
                    credit: $invoiceTotal,
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

            $returnedSubTotal = 0.0;
            $returnedDiscount = 0.0;
            $returnedTax = 0.0;
            $returnedTotal = 0.0;

            foreach ($validated['items'] as $retItem) {
                $lineItem = SalesInvoiceItem::where('id', $retItem['sales_invoice_item_id'])
                    ->where('sales_invoice_id', $original->id)
                    ->firstOrFail();

                $returnQty = min($retItem['return_qty'], $lineItem->qty);
                if ($returnQty <= 0) {
                    continue;
                }

                $originalQty = (float) $lineItem->qty;
                $perUnitBase = $originalQty > 0 ? round(((float) $lineItem->rate * $originalQty) / $originalQty, 4) : 0.0;
                $perUnitDiscount = $originalQty > 0 ? round((float) $lineItem->discount_amount / $originalQty, 4) : 0.0;
                $perUnitTaxable = $originalQty > 0 ? round((float) $lineItem->taxable_amount / $originalQty, 4) : 0.0;
                $perUnitTax = $originalQty > 0 ? round((float) $lineItem->gst_amount / $originalQty, 4) : 0.0;
                $perUnitTotal = $originalQty > 0 ? round((float) $lineItem->total_amount / $originalQty, 4) : 0.0;

                $lineBaseReturn = round($returnQty * $perUnitBase, 2);
                $lineDiscountReturn = round($returnQty * $perUnitDiscount, 2);
                $lineTaxableReturn = round($returnQty * $perUnitTaxable, 2);
                $lineTaxReturn = round($returnQty * $perUnitTax, 2);
                $lineTotalReturn = round($returnQty * $perUnitTotal, 2);

                $returnedSubTotal += $lineBaseReturn;
                $returnedDiscount += $lineDiscountReturn;
                $returnedTax += $lineTaxReturn;
                $returnedTotal += $lineTotalReturn;

                $remainingQty = round($originalQty - $returnQty, 2);

                if ($remainingQty <= 0) {
                    $lineItem->delete();
                } else {
                    $lineItem->update([
                        'qty' => $remainingQty,
                        'discount_amount' => round(max(0, (float) $lineItem->discount_amount - $lineDiscountReturn), 2),
                        'taxable_amount' => round(max(0, (float) $lineItem->taxable_amount - $lineTaxableReturn), 2),
                        'gst_amount' => round(max(0, (float) $lineItem->gst_amount - $lineTaxReturn), 2),
                        'total_amount' => round(max(0, (float) $lineItem->total_amount - $lineTotalReturn), 2),
                    ]);
                }

                // Add stock back
                $inventoryService->recordSaleReturn([
                    'product_id'    => $lineItem->product_id,
                    'batch_no'      => $lineItem->batch_no,
                    'expiry_date'   => $lineItem->exp_date,
                    'mrp'           => $lineItem->mrp,
                    'franchisee_id' => $franchiseeId,
                    'qty'           => $returnQty,
                    'rate'          => $lineItem->rate,
                    'reference_id'  => $original->id,
                    'created_by'    => $request->user()->id,
                ]);
            }

            $newSubTotal = round(max(0, (float) $original->sub_total - $returnedSubTotal), 2);
            $newDiscount = round(max(0, (float) $original->total_discount_amount - $returnedDiscount), 2);
            $newTax = round(max(0, (float) $original->total_tax_amount - $returnedTax), 2);
            $grossTotal = round(($newSubTotal - $newDiscount) + $newTax + (float) $original->other_charges, 2);

            $original->update([
                'sub_total' => $newSubTotal,
                'total_discount_amount' => $newDiscount,
                'total_tax_amount' => $newTax,
                'total_amount' => round(max(0, $grossTotal), 0),
            ]);

            return response()->json([
                'success'       => true,
                'return_amount' => round($returnedTotal, 2),
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

    /**
     * Build customer credit snapshot using invoice-level outstanding.
     */
    private function customerCreditSnapshot(int $customerId, int $franchiseeId, int $limit = 10): array
    {
        $invoices = SalesInvoice::query()
            ->where('customer_id', $customerId)
            ->where('franchisee_id', $franchiseeId)
            ->where('status', 'completed')
            ->latest('date_time')
            ->limit($limit)
            ->get(['id', 'bill_no', 'date_time', 'total_amount']);

        $recentBills = [];
        $pendingCredit = 0.0;

        foreach ($invoices as $invoice) {
            $creditRaised = (float) SalePayment::query()
                ->where('sales_invoice_id', $invoice->id)
                ->sum('credit_amount');

            $collected = (float) CustomerCreditCollection::query()
                ->where('sales_invoice_id', $invoice->id)
                ->sum('amount');

            $outstanding = round(max(0, $creditRaised - $collected), 2);
            $pendingCredit += $outstanding;

            $recentBills[] = [
                'id' => $invoice->id,
                'bill_no' => $invoice->bill_no,
                'date_time' => $invoice->date_time,
                'total_amount' => (float) $invoice->total_amount,
                'credit_amount' => $creditRaised,
                'collected_amount' => $collected,
                'outstanding_credit' => $outstanding,
            ];
        }

        $recentCollections = CustomerCreditCollection::query()
            ->where('customer_id', $customerId)
            ->where('franchisee_id', $franchiseeId)
            ->latest('collected_at')
            ->latest('id')
            ->limit(10)
            ->get([
                'id',
                'sales_invoice_id',
                'amount',
                'payment_mode',
                'transaction_no',
                'collected_at',
                'narration',
            ]);

        return [
            'pending_credit' => round($pendingCredit, 2),
            'recent_bills' => $recentBills,
            'recent_collections' => $recentCollections,
        ];
    }
}
