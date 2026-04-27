<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\PosHold;
use App\Models\PosShift;
use App\Models\PosOverrideAudit;
use App\Models\SalePayment;
use App\Models\CustomerCreditCollection;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Models\Franchisee;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Events\SaleCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class POSController extends Controller
{
    private const HOLD_LOCK_TTL_SECONDS = 120;
    private const SHIFT_STATUS_OPEN = 'open';
    private const SHIFT_STATUS_CLOSED = 'closed';

    public function index(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);
        $settings = $this->resolvePosSettings($user);

        return Inertia::render('POS/Index', [
            'franchisee_id' => $franchiseeId,
            'pos_preferences' => [
                'round_off_enabled' => (bool) ($settings['round_off_enabled'] ?? true),
                'round_off_mode' => (string) ($settings['round_off_mode'] ?? 'nearest'),
                'supervisor_override_enabled' => (bool) ($settings['supervisor_override_enabled'] ?? true),
                'supervisor_override_discount_threshold' => (float) ($settings['supervisor_override_discount_threshold'] ?? 15),
                'auto_print_after_checkout' => (bool) ($settings['auto_print_after_checkout'] ?? true),
                'auto_open_invoice_after_checkout' => (bool) ($settings['auto_open_invoice_after_checkout'] ?? true),
                'auto_lock_bill_on_hold' => (bool) ($settings['auto_lock_bill_on_hold'] ?? false),
                'smart_batch_suggestion' => (bool) ($settings['smart_batch_suggestion'] ?? true),
                'receipt_layout' => (string) ($settings['receipt_layout'] ?? 'thermal'),
                'printer_paper_width' => (string) ($settings['printer_paper_width'] ?? '80mm'),
                'csv_format' => (string) ($settings['csv_format'] ?? 'marg'),
            ],
            'active_shift' => $this->serializeShift($this->findActiveShift($franchiseeId, (int) $user->id)),
        ]);
    }

    public function shiftStatus(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        return response()->json([
            'active_shift' => $this->serializeShift($this->findActiveShift($franchiseeId, (int) $user->id)),
        ]);
    }

    public function openShift(Request $request)
    {
        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:300'],
        ]);

        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $shift = DB::transaction(function () use ($franchiseeId, $user, $validated) {
            $active = PosShift::query()
                ->where('franchisee_id', $franchiseeId)
                ->where('user_id', (int) $user->id)
                ->where('status', self::SHIFT_STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($active) {
                abort(422, 'Shift is already open for this counter user.');
            }

            return PosShift::create([
                'shift_no' => $this->generateShiftNo($user->franchisee?->shop_code ?? 'SHOP'),
                'franchisee_id' => $franchiseeId,
                'user_id' => (int) $user->id,
                'status' => self::SHIFT_STATUS_OPEN,
                'opening_cash' => round((float) $validated['opening_cash'], 2),
                'opening_note' => !empty($validated['opening_note']) ? trim((string) $validated['opening_note']) : null,
                'opened_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'active_shift' => $this->serializeShift($shift),
        ]);
    }

    public function closeShift(Request $request)
    {
        $validated = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:300'],
        ]);

        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $closed = DB::transaction(function () use ($franchiseeId, $user, $validated) {
            $shift = PosShift::query()
                ->where('franchisee_id', $franchiseeId)
                ->where('user_id', (int) $user->id)
                ->where('status', self::SHIFT_STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if (!$shift) {
                abort(422, 'No active shift found to close.');
            }

            $summary = $this->computeShiftSummary($franchiseeId, (int) $user->id, $shift->opened_at, now());

            $openingCash = round((float) $shift->opening_cash, 2);
            $expectedCash = round($openingCash + (float) ($summary['cash_sales'] ?? 0), 2);
            $closingCash = round((float) $validated['closing_cash'], 2);
            $variance = round($closingCash - $expectedCash, 2);

            $shift->status = self::SHIFT_STATUS_CLOSED;
            $shift->closing_cash = $closingCash;
            $shift->expected_cash = $expectedCash;
            $shift->cash_variance = $variance;
            $shift->summary_payload = $summary;
            $shift->closing_note = !empty($validated['closing_note']) ? trim((string) $validated['closing_note']) : null;
            $shift->closed_by = (int) $user->id;
            $shift->closed_at = now();
            $shift->save();

            return $shift;
        });

        return response()->json([
            'success' => true,
            'closed_shift' => $this->serializeShift($closed),
        ]);
    }

    public function settings(Request $request)
    {
        return Inertia::render('POS/Settings', [
            'preferences' => $this->resolvePosSettings($request->user()),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'round_off_enabled' => ['boolean'],
            'round_off_mode' => ['nullable', 'string', 'in:nearest,up,down,none'],
            'supervisor_override_enabled' => ['boolean'],
            'supervisor_override_discount_threshold' => ['nullable', 'numeric', 'between:0,100'],
            'receipt_layout' => ['nullable', 'string', 'in:thermal,a4'],
            'auto_print_after_checkout' => ['boolean'],
            'printer_paper_width' => ['nullable', 'string', 'in:58mm,72mm,80mm,a4'],
            'print_copies' => ['nullable', 'integer', 'between:1,5'],
            'printer_name' => ['nullable', 'string', 'max:120'],
            'bill_logo_url' => ['nullable', 'string', 'max:500'],
            'bill_header_line_1' => ['nullable', 'string', 'max:120'],
            'bill_header_line_2' => ['nullable', 'string', 'max:120'],
            'csv_format' => ['nullable', 'string', 'in:marg,acme,medvision'],
            'auto_open_invoice_after_checkout' => ['boolean'],
            'auto_lock_bill_on_hold' => ['boolean'],
            'smart_batch_suggestion' => ['boolean'],
        ]);

        $user = $request->user();
        $current = is_array($user->preferences) ? $user->preferences : [];

        $user->preferences = array_merge($this->resolvePosSettings($user), $current, $validated);
        $user->save();

        return back()->with('success', 'POS settings updated successfully.');
    }

    public function estimatePrint(Request $request)
    {
        $payload = $this->normalizeEstimatePayload($request);

        return response()->view('exports.pos-estimate', [
            'estimate' => $payload,
            'shop_name' => $request->user()?->franchisee?->shop_name ?? 'BrainYug POS',
        ]);
    }

    public function estimateExport(Request $request)
    {
        $payload = $this->normalizeEstimatePayload($request);

        $filename = 'pos_estimate_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Product', 'Batch', 'Qty', 'Rate', 'GST%', 'Line Total']);
            foreach ($payload['items'] as $item) {
                fputcsv($out, [
                    $item['product_name'],
                    $item['batch_no'],
                    number_format((float) $item['qty'], 2, '.', ''),
                    number_format((float) $item['rate'], 2, '.', ''),
                    number_format((float) $item['gst_percent'], 2, '.', ''),
                    number_format((float) $item['total_amount'], 2, '.', ''),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Subtotal', '', '', '', '', number_format((float) $payload['totals']['sub_total'], 2, '.', '')]);
            fputcsv($out, ['Discount', '', '', '', '', number_format((float) $payload['totals']['discount_total'], 2, '.', '')]);
            fputcsv($out, ['Tax', '', '', '', '', number_format((float) $payload['totals']['tax_amount'], 2, '.', '')]);
            fputcsv($out, ['Other Charges', '', '', '', '', number_format((float) $payload['totals']['other_charges'], 2, '.', '')]);
            fputcsv($out, ['Round Off', '', '', '', '', number_format((float) $payload['totals']['round_off'], 2, '.', '')]);
            fputcsv($out, ['Grand Total', '', '', '', '', number_format((float) $payload['totals']['total'], 2, '.', '')]);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function authorizeOverride(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:checkout_discount_override,return_override,cancel_invoice_override'],
            'request_id' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:5', 'max:160'],
            'supervisor_username' => ['required', 'string', 'max:80'],
            'supervisor_password' => ['required', 'string', 'max:120'],
            'approval_snapshot' => ['nullable', 'array'],
            'approval_snapshot.item_count' => ['nullable', 'integer', 'min:1'],
            'approval_snapshot.max_line_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'approval_snapshot.bill_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'approval_snapshot.total_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $cashier = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($cashier);

        $supervisor = User::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(username) = ?', [strtolower(trim((string) $validated['supervisor_username']))])
            ->first();

        if (!$supervisor || !Hash::check((string) $validated['supervisor_password'], (string) $supervisor->password)) {
            abort(422, 'Invalid supervisor credentials.');
        }

        if ((int) $supervisor->id === (int) $cashier->id) {
            abort(422, 'Self-approval is not allowed. Please request another supervisor.');
        }

        if (!$this->isSupervisorOverrideRoleAllowed($supervisor)) {
            abort(422, 'Selected user is not eligible for supervisor overrides.');
        }

        if (!$supervisor->isAdmin()) {
            $supervisorFranchiseeId = $this->resolveFranchiseeId($supervisor);
            if ($supervisorFranchiseeId !== $franchiseeId) {
                abort(422, 'Supervisor must belong to the same franchise context.');
            }
        }

        $token = Str::random(48);
        $expiresAt = now()->addMinutes(5);
        $snapshot = $this->normalizeOverrideSnapshot($validated['approval_snapshot'] ?? null);
        $audit = PosOverrideAudit::create([
            'franchisee_id' => $franchiseeId,
            'cashier_user_id' => (int) $cashier->id,
            'supervisor_user_id' => (int) $supervisor->id,
            'action' => (string) $validated['action'],
            'request_id' => (string) $validated['request_id'],
            'token_hash' => hash('sha256', $token),
            'status' => 'approved',
            'reason' => trim((string) $validated['reason']),
            'approval_snapshot' => $snapshot,
            'approved_at' => now(),
            'expires_at' => $expiresAt,
        ]);
        $cacheKey = $this->overrideApprovalCacheKey($franchiseeId, (int) $cashier->id, $token);

        Cache::put($cacheKey, [
            'action' => (string) $validated['action'],
            'request_id' => (string) $validated['request_id'],
            'reason' => trim((string) $validated['reason']),
            'approval_snapshot' => $snapshot,
            'audit_id' => (int) $audit->id,
            'cashier_user_id' => (int) $cashier->id,
            'supervisor_user_id' => (int) $supervisor->id,
            'supervisor_name' => (string) $supervisor->name,
            'approved_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return response()->json([
            'success' => true,
            'token' => $token,
            'action' => (string) $validated['action'],
            'request_id' => (string) $validated['request_id'],
            'reason' => trim((string) $validated['reason']),
            'supervisor_name' => (string) $supervisor->name,
            'expires_at' => $expiresAt->toIso8601String(),
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
     * Persist a parked POS bill so multi-counter work survives browser refresh/crash.
     */
    public function saveHold(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $validated = $request->validate([
            'hold_id' => 'nullable|integer|exists:pos_holds,id',
            'tab_code' => 'required|string|max:12',
            'is_locked' => 'nullable|boolean',
            'subtotal' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'customer' => 'nullable|array',
            'doctor' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'meta' => 'nullable|array',
        ]);

        $hold = DB::transaction(function () use ($validated, $franchiseeId, $user) {
            $hold = null;

            if (!empty($validated['hold_id'])) {
                $hold = PosHold::query()
                    ->where('id', (int) $validated['hold_id'])
                    ->where('franchisee_id', $franchiseeId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($hold) {
                    $this->assertHoldEditorAccess($hold, (int) $user->id);
                }
            }

            if (!$hold) {
                $hold = PosHold::query()
                    ->where('franchisee_id', $franchiseeId)
                    ->where('user_id', $user->id)
                    ->where('tab_code', $validated['tab_code'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($hold) {
                    $this->assertHoldEditorAccess($hold, (int) $user->id);
                }
            }

            if (!$hold) {
                $hold = new PosHold();
                $hold->hold_no = $this->generateHoldNo($user->franchisee?->shop_code ?? 'SHOP');
                $hold->franchisee_id = $franchiseeId;
                $hold->user_id = $user->id;
            }

            $hold->tab_code = $validated['tab_code'];
            $hold->status = 'active';
            $hold->is_locked = (bool) ($validated['is_locked'] ?? false);
            $hold->subtotal = round((float) ($validated['subtotal'] ?? 0), 2);
            $hold->discount_amount = round((float) ($validated['discount_amount'] ?? 0), 2);
            $hold->tax_amount = round((float) ($validated['tax_amount'] ?? 0), 2);
            $hold->total_amount = round((float) ($validated['total_amount'] ?? 0), 2);
            $hold->customer_payload = $validated['customer'] ?? null;
            $hold->doctor_payload = $validated['doctor'] ?? null;
            $hold->items_payload = $validated['items'];
            $hold->meta_payload = $validated['meta'] ?? null;
            $hold->held_at = now();
            $hold->lock_owner_user_id = (int) $user->id;
            $hold->lock_expires_at = now()->addSeconds(self::HOLD_LOCK_TTL_SECONDS);
            $hold->save();

            return $hold;
        });

        return response()->json([
            'success' => true,
            'hold_id' => $hold->id,
            'hold_no' => $hold->hold_no,
            'held_at' => $hold->held_at,
            'lock_expires_at' => $hold->lock_expires_at,
        ]);
    }

    public function listHolds(Request $request)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        $holds = PosHold::query()
            ->where('franchisee_id', $franchiseeId)
            ->where('status', 'active')
            ->latest('held_at')
            ->limit(50)
            ->get([
                'id',
                'hold_no',
                'tab_code',
                'user_id',
                'lock_owner_user_id',
                'is_locked',
                'subtotal',
                'discount_amount',
                'tax_amount',
                'total_amount',
                'held_at',
                'lock_expires_at',
            ]);

        return response()->json($holds);
    }

    public function loadHold(Request $request, PosHold $posHold)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());
        $userId = (int) $request->user()->id;

        if ((int) $posHold->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $hold = PosHold::query()
            ->where('id', (int) $posHold->id)
            ->where('franchisee_id', $franchiseeId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($hold->status !== 'active') {
            abort(422, 'Hold is no longer active.');
        }

        $this->assertHoldEditorAccess($hold, $userId);

        $hold->lock_owner_user_id = $userId;
        $hold->lock_expires_at = now()->addSeconds(self::HOLD_LOCK_TTL_SECONDS);
        $hold->save();

        return response()->json([
            'id' => $hold->id,
            'hold_no' => $hold->hold_no,
            'tab_code' => $hold->tab_code,
            'is_locked' => (bool) $hold->is_locked,
            'subtotal' => (float) $hold->subtotal,
            'discount_amount' => (float) $hold->discount_amount,
            'tax_amount' => (float) $hold->tax_amount,
            'total_amount' => (float) $hold->total_amount,
            'customer' => $hold->customer_payload,
            'doctor' => $hold->doctor_payload,
            'items' => $hold->items_payload,
            'meta' => $hold->meta_payload,
            'held_at' => $hold->held_at,
            'lock_owner_user_id' => $hold->lock_owner_user_id,
            'lock_expires_at' => $hold->lock_expires_at,
        ]);
    }

    public function cancelHold(Request $request, PosHold $posHold)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        if ((int) $posHold->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        if ($posHold->status !== 'active') {
            return response()->json(['success' => true]);
        }

        $posHold->update([
            'status' => 'cancelled',
            'released_at' => now(),
            'is_locked' => false,
            'lock_owner_user_id' => null,
            'lock_expires_at' => null,
        ]);

        return response()->json(['success' => true]);
    }

    public function releaseHoldLock(Request $request, PosHold $posHold)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        if ((int) $posHold->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        if ($posHold->status !== 'active') {
            return response()->json(['success' => true]);
        }

        $hold = PosHold::query()
            ->where('id', (int) $posHold->id)
            ->where('franchisee_id', $franchiseeId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($hold->status !== 'active') {
            return response()->json(['success' => true]);
        }

        $lockOwnerId = (int) ($hold->lock_owner_user_id ?? 0);
        $isExpired = !$hold->lock_expires_at || $hold->lock_expires_at->isPast();

        if ($lockOwnerId > 0 && $lockOwnerId !== (int) $user->id && !$isExpired) {
            abort(409, 'Hold lock is owned by another counter and cannot be released.');
        }

        $hold->lock_owner_user_id = null;
        $hold->lock_expires_at = null;
        $hold->save();

        return response()->json(['success' => true]);
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
     * Fetch recent completed bills for a selected customer.
     * Legacy parity: getPrevBillRecord() + getPrevBillSalesRecordByID().
     */
    public function customerRecentBills(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        Customer::query()
            ->where('id', (int) $validated['customer_id'])
            ->where('franchisee_id', $franchiseeId)
            ->firstOrFail(['id']);

        $limit = (int) ($validated['limit'] ?? 5);

        $bills = SalesInvoice::query()
            ->with('payments:id,sales_invoice_id,payment_mode,cash_amount,bank_amount,credit_amount')
            ->where('franchisee_id', $franchiseeId)
            ->where('customer_id', (int) $validated['customer_id'])
            ->where('status', 'completed')
            ->latest('date_time')
            ->limit($limit)
            ->get(['id', 'bill_no', 'date_time', 'total_amount'])
            ->map(function (SalesInvoice $invoice) {
                return [
                    'id' => $invoice->id,
                    'bill_no' => $invoice->bill_no,
                    'date_time' => $invoice->date_time,
                    'total_amount' => (float) $invoice->total_amount,
                    'payment_modes' => $invoice->payments->pluck('payment_mode')->filter()->values(),
                ];
            })
            ->values();

        return response()->json($bills);
    }

    /**
     * Fetch line items from one invoice so return UI can prefill from actual sale.
     */
    public function billItems(Request $request, SalesInvoice $salesInvoice)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        if ((int) $salesInvoice->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $salesInvoice->load([
            'customer:id,name,mobile',
            'items:id,sales_invoice_id,product_id,batch_no,exp_date,qty,free_qty,mrp,rate,discount_percent,gst_percent,total_amount',
            'items.product:id,product_name,sku',
        ]);

        return response()->json([
            'invoice' => [
                'id' => $salesInvoice->id,
                'bill_no' => $salesInvoice->bill_no,
                'date_time' => $salesInvoice->date_time,
                'total_amount' => (float) $salesInvoice->total_amount,
                'customer' => $salesInvoice->customer,
            ],
            'items' => $salesInvoice->items->map(function (SalesInvoiceItem $item) {
                return [
                    'sales_invoice_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->product_name,
                    'sku' => $item->product?->sku,
                    'batch_no' => $item->batch_no,
                    'exp_date' => $item->exp_date,
                    'qty' => (float) $item->qty,
                    'free_qty' => (float) ($item->free_qty ?? 0),
                    'mrp' => (float) $item->mrp,
                    'rate' => (float) $item->rate,
                    'discount_percent' => (float) $item->discount_percent,
                    'gst_percent' => (float) $item->gst_percent,
                    'total_amount' => (float) $item->total_amount,
                ];
            })->values(),
        ]);
    }

    /**
     * Save POS cart as quotation for follow-up billing.
     */
    public function storeQuotation(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'remarks' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.batch_no' => 'nullable|string|max:50',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'sub_total' => 'required|numeric|min:0',
            'total_discount_amount' => 'required|numeric|min:0',
            'total_tax_amount' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
        ]);

        Customer::query()
            ->where('id', (int) $validated['customer_id'])
            ->where('franchisee_id', $franchiseeId)
            ->firstOrFail(['id']);

        if (!empty($validated['doctor_id'])) {
            Doctor::query()
                ->where('id', (int) $validated['doctor_id'])
                ->where('franchisee_id', $franchiseeId)
                ->firstOrFail(['id']);
        }

        $quotation = DB::transaction(function () use ($validated, $user, $franchiseeId) {
            $franchisee = Franchisee::query()->findOrFail($franchiseeId);

            $quotation = SalesQuotation::create([
                'quotation_no' => $this->generateQuotationNo((string) ($franchisee->shop_code ?? 'SHOP')),
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'customer_id' => (int) $validated['customer_id'],
                'doctor_id' => !empty($validated['doctor_id']) ? (int) $validated['doctor_id'] : null,
                'quotation_date' => now(),
                'status' => 'active',
                'sub_total' => round((float) $validated['sub_total'], 2),
                'total_discount_amount' => round((float) $validated['total_discount_amount'], 2),
                'total_tax_amount' => round((float) $validated['total_tax_amount'], 2),
                'total_amount' => round((float) $validated['total_amount'], 2),
                'remarks' => $validated['remarks'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $qty = round((float) $item['qty'], 4);
                $rate = round((float) $item['rate'], 2);
                $discountPercent = round((float) ($item['discount_percent'] ?? 0), 2);
                $lineBase = round($qty * $rate, 4);
                $discountAmount = round($lineBase * ($discountPercent / 100), 2);
                $taxableAmount = round($lineBase - $discountAmount, 2);

                $product = Product::query()
                    ->with('hsn:id,cgst_percent,sgst_percent,igst_percent')
                    ->findOrFail((int) $item['product_id']);
                $gstPercent = round((float) ($product->gstPercent() ?? 0), 2);
                $gstAmount = round($taxableAmount * ($gstPercent / 100), 2);

                SalesQuotationItem::create([
                    'sales_quotation_id' => $quotation->id,
                    'product_id' => (int) $item['product_id'],
                    'batch_no' => !empty($item['batch_no']) ? (string) $item['batch_no'] : null,
                    'qty' => $qty,
                    'free_qty' => round((float) ($item['free_qty'] ?? 0), 2),
                    'mrp' => round((float) $item['mrp'], 2),
                    'rate' => $rate,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'taxable_amount' => $taxableAmount,
                    'gst_percent' => $gstPercent,
                    'gst_amount' => $gstAmount,
                    'total_amount' => round($taxableAmount + $gstAmount, 2),
                ]);
            }

            return $quotation;
        });

        return response()->json([
            'success' => true,
            'quotation_id' => $quotation->id,
            'quotation_no' => $quotation->quotation_no,
        ]);
    }

    public function customerQuotations(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'status' => 'nullable|string|in:active,converted,cancelled,all',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $franchiseeId = $this->resolveFranchiseeId($request->user());

        Customer::query()
            ->where('id', (int) $validated['customer_id'])
            ->where('franchisee_id', $franchiseeId)
            ->firstOrFail(['id']);

        $query = SalesQuotation::query()
            ->where('franchisee_id', $franchiseeId)
            ->where('customer_id', (int) $validated['customer_id'])
            ->withCount('items')
            ->latest('quotation_date');

        $status = (string) ($validated['status'] ?? 'active');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $records = $query
            ->limit((int) ($validated['limit'] ?? 10))
            ->get(['id', 'quotation_no', 'quotation_date', 'status', 'total_amount', 'remarks', 'sales_invoice_id'])
            ->map(function (SalesQuotation $quotation) {
                return [
                    'id' => $quotation->id,
                    'quotation_no' => $quotation->quotation_no,
                    'quotation_date' => $quotation->quotation_date,
                    'status' => $quotation->status,
                    'items_count' => $quotation->items_count,
                    'total_amount' => (float) $quotation->total_amount,
                    'remarks' => $quotation->remarks,
                    'sales_invoice_id' => $quotation->sales_invoice_id,
                ];
            })
            ->values();

        return response()->json($records);
    }

    public function quotationDetails(Request $request, SalesQuotation $salesQuotation)
    {
        $franchiseeId = $this->resolveFranchiseeId($request->user());

        if ((int) $salesQuotation->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $salesQuotation->load([
            'customer:id,name,mobile',
            'doctor:id,name,reg_no',
            'items:id,sales_quotation_id,product_id,batch_no,qty,free_qty,mrp,rate,discount_percent,gst_percent,total_amount',
            'items.product:id,product_name,sku',
        ]);

        return response()->json([
            'quotation' => [
                'id' => $salesQuotation->id,
                'quotation_no' => $salesQuotation->quotation_no,
                'quotation_date' => $salesQuotation->quotation_date,
                'status' => $salesQuotation->status,
                'customer' => $salesQuotation->customer,
                'doctor' => $salesQuotation->doctor,
                'sub_total' => (float) $salesQuotation->sub_total,
                'total_discount_amount' => (float) $salesQuotation->total_discount_amount,
                'total_tax_amount' => (float) $salesQuotation->total_tax_amount,
                'total_amount' => (float) $salesQuotation->total_amount,
                'remarks' => $salesQuotation->remarks,
            ],
            'items' => $salesQuotation->items->map(function (SalesQuotationItem $item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->product_name,
                    'sku' => $item->product?->sku,
                    'batch_no' => $item->batch_no,
                    'qty' => (float) $item->qty,
                    'free_qty' => (float) ($item->free_qty ?? 0),
                    'mrp' => (float) $item->mrp,
                    'rate' => (float) $item->rate,
                    'discount_percent' => (float) $item->discount_percent,
                    'gst_percent' => (float) $item->gst_percent,
                    'total_amount' => (float) $item->total_amount,
                ];
            })->values(),
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
            'bill_discount_percent'    => 'nullable|numeric|min:0|max:100',
            'other_charges'            => 'nullable|numeric|min:0',
            'round_off'                => 'nullable|numeric',
            'total_amount'             => 'required|numeric',
            'request_id'               => 'nullable|string|max:80',
            'override_token'           => 'nullable|string|max:120',
            'override_reason'          => 'nullable|string|max:160',
            'override_snapshot'        => 'nullable|array',
            'override_snapshot.item_count' => 'nullable|integer|min:1',
            'override_snapshot.max_line_discount' => 'nullable|numeric|min:0|max:100',
            'override_snapshot.bill_discount_percent' => 'nullable|numeric|min:0|max:100',
            'override_snapshot.total_amount' => 'nullable|numeric|min:0',
            'hold_id'                  => 'nullable|integer|exists:pos_holds,id',
            'quotation_id'             => 'nullable|integer|exists:sales_quotations,id',
        ]);

        $validated['cash_amount'] = round((float) $validated['cash_amount'], 2);
        $validated['bank_amount'] = round((float) $validated['bank_amount'], 2);
        $validated['credit_amount'] = round((float) $validated['credit_amount'], 2);
        $roundingConfig = $this->resolveRoundOffPreferences($user);
        $this->requireActiveShift($franchiseeId, (int) $user->id);
        $overrideThreshold = $this->resolveSupervisorOverrideDiscountThreshold($user);
        $overrideRequired = $this->requiresSupervisorOverride($validated, $overrideThreshold);

        if ($overrideRequired) {
            $requestId = trim((string) ($validated['request_id'] ?? ''));
            if ($requestId === '') {
                abort(422, 'Supervisor approval required: checkout request reference is missing. Please reopen checkout and retry.');
            }

            $approval = $this->resolveOverrideApproval(
                franchiseeId: $franchiseeId,
                cashierUserId: (int) $user->id,
                token: (string) ($validated['override_token'] ?? ''),
                requestId: $requestId,
                action: 'checkout_discount_override'
            );

            if (!$approval) {
                abort(422, 'Supervisor approval required for high discount checkout.');
            }

            $approvedReason = trim((string) ($approval['reason'] ?? ''));
            $providedReason = trim((string) ($validated['override_reason'] ?? ''));
            if ($providedReason !== '' && strcasecmp($providedReason, $approvedReason) !== 0) {
                abort(422, 'Supervisor reason mismatch. Please request approval again.');
            }

            $approvedSnapshot = $this->normalizeOverrideSnapshot($approval['approval_snapshot'] ?? null);
            $providedSnapshot = $this->normalizeOverrideSnapshot($validated['override_snapshot'] ?? null);
            $derivedSnapshot = $this->deriveCheckoutOverrideSnapshot($validated);

            if (!$approvedSnapshot || !$providedSnapshot || $providedSnapshot !== $approvedSnapshot || $derivedSnapshot !== $approvedSnapshot) {
                abort(422, 'Supervisor approval no longer matches this checkout payload. Please re-approve.');
            }

            $validated['override_reason'] = $approvedReason;
            $validated['override_snapshot'] = $approvedSnapshot;
            $validated['override_cache_key'] = !empty($approval['__cache_key']) ? (string) $approval['__cache_key'] : null;
            $validated['override_audit_id'] = !empty($approval['audit_id']) ? (int) $approval['audit_id'] : null;
        }

        $processCheckout = function () use ($validated, $user, $inventoryService, $ledgerService, $franchiseeId, $roundingConfig) {
            return DB::transaction(function () use ($validated, $user, $inventoryService, $ledgerService, $franchiseeId, $roundingConfig) {
                if (SalesInvoice::where('bill_no', $validated['bill_no'])->lockForUpdate()->exists()) {
                    abort(422, "Bill number {$validated['bill_no']} is already used. Please regenerate bill number and retry.");
                }

                $linkedHold = null;
                if (!empty($validated['hold_id'])) {
                    $linkedHold = PosHold::query()
                        ->where('id', (int) $validated['hold_id'])
                        ->where('franchisee_id', $franchiseeId)
                        ->lockForUpdate()
                        ->first();

                    if (!$linkedHold || $linkedHold->status !== 'active') {
                        abort(422, 'Selected hold is no longer active. Please reload POS tab.');
                    }
                }

                // Resolve or create customer
                $customerId = $validated['customer_id'] ?? null;
                if ($customerId) {
                    $customer = Customer::query()
                        ->where('id', $customerId)
                        ->where('franchisee_id', $franchiseeId)
                        ->first();

                    if (!$customer) {
                        abort(422, 'Selected customer does not belong to this franchise. Please refresh customer selection.');
                    }
                }

            if (!$customerId && !empty($validated['customer_mobile'])) {
                $customer = Customer::firstOrCreate(
                    ['mobile' => $validated['customer_mobile'], 'franchisee_id' => $franchiseeId],
                    ['name' => $validated['customer_name'] ?? 'Walk-in']
                );
                $customerId = $customer->id;
            }

            if (!empty($validated['doctor_id'])) {
                $doctor = Doctor::query()
                    ->where('id', (int) $validated['doctor_id'])
                    ->where('franchisee_id', $franchiseeId)
                    ->first();

                if (!$doctor) {
                    abort(422, 'Selected doctor does not belong to this franchise. Please refresh doctor selection.');
                }
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

                $conversionFactor = $this->normalizeConversionFactor($product?->conversion_factor ?? 1);
                $requestedQty = (float) $item['qty'] + (float) ($item['free_qty'] ?? 0);
                $stockQtyRequired = $this->toStockUnits($requestedQty, $conversionFactor);

                if ($stockQtyRequired > (float) $availableStock) {
                    $name = $product?->product_name ?? "Product #{$item['product_id']}";
                    abort(422, "Insufficient stock for {$name} batch '{$item['batch_no']}'. Available: {$availableStock}, Requested stock units: {$stockQtyRequired} (sale qty {$requestedQty}, conversion {$conversionFactor}).");
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
                $conversionFactor = $this->normalizeConversionFactor($product?->conversion_factor ?? 1);

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
                    'conversion_factor' => $conversionFactor,
                    'stock_qty' => $this->toStockUnits(((float) $item['qty'] + (float) ($item['free_qty'] ?? 0)), $conversionFactor),
                ];
            }

            $otherCharges = round((float) ($validated['other_charges'] ?? 0), 2);
            $billDiscountPercent = round((float) ($validated['bill_discount_percent'] ?? 0), 2);
            $baseBeforeHeaderDiscount = round(($summarySubTotal - $summaryDiscount) + $summaryTax, 2);
            $headerDiscountAmount = round($baseBeforeHeaderDiscount * ($billDiscountPercent / 100), 2);
            $grossTotal = round($baseBeforeHeaderDiscount - $headerDiscountAmount + $otherCharges, 2);
            $rounding = $this->calculateRoundOff($grossTotal, $roundingConfig);
            $invoiceTotal = $rounding['total'];
            $roundOffAmount = $rounding['round_off'];

            $clientSubTotal = round((float) ($validated['sub_total'] ?? 0), 2);
            $clientDiscount = round((float) ($validated['total_discount_amount'] ?? 0), 2);
            $clientTax = round((float) ($validated['total_tax_amount'] ?? 0), 2);
            $clientGrand = round((float) ($validated['total_amount'] ?? 0), 2);
            $clientRoundOff = round((float) ($validated['round_off'] ?? ($clientGrand - $grossTotal)), 2);
            $summaryDiscountWithHeader = round($summaryDiscount + $headerDiscountAmount, 2);

            if (abs($clientSubTotal - round($summarySubTotal, 2)) > 0.01 ||
                abs($clientDiscount - $summaryDiscountWithHeader) > 0.01 ||
                abs($clientTax - round($summaryTax, 2)) > 0.01 ||
                abs($clientGrand - $invoiceTotal) > 0.01) {
                abort(422, 'Bill totals changed during checkout. Please review cart and submit again.');
            }

            if (abs($clientRoundOff - $roundOffAmount) > 0.01) {
                abort(422, 'Round-off changed during checkout. Please review totals and retry.');
            }

            $this->assertCheckoutPaymentValidity($validated, $invoiceTotal, $customerId);

            $invoice = SalesInvoice::create([
                'bill_no' => $validated['bill_no'],
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'doctor_id' => $validated['doctor_id'] ?? null,
                'date_time' => now(),
                'sub_total' => round($summarySubTotal, 2),
                'total_discount_amount' => $summaryDiscountWithHeader,
                'total_tax_amount' => round($summaryTax, 2),
                'other_charges' => $otherCharges,
                'round_off' => $roundOffAmount,
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
                    'qty' => (float) $linePayload['stock_qty'],
                    'rate' => $linePayload['rate'],
                    'reference_id' => $invoice->id,
                    'created_by' => $user->id,
                ]);
            }

            // Record payment split
            SalePayment::create([
                'sales_invoice_id' => $invoice->id,
                'payment_mode'     => $validated['payment_mode'],
                'cash_amount'      => round((float) $validated['cash_amount'], 2),
                'bank_amount'      => round((float) $validated['bank_amount'], 2),
                'credit_amount'    => round((float) $validated['credit_amount'], 2),
                'transaction_no'   => $validated['transaction_no'] ?? null,
                'wallet_type'      => $validated['wallet_type'] ?? null,
            ]);

            // Financial ledger entry for the franchisee's cash / bank
            if ($invoiceTotal > 0) {
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

            if ($linkedHold) {
                $linkedHold->update([
                    'status' => 'completed',
                    'sales_invoice_id' => $invoice->id,
                    'released_at' => now(),
                    'is_locked' => false,
                    'lock_owner_user_id' => null,
                    'lock_expires_at' => null,
                ]);
            }

            if (!empty($validated['quotation_id'])) {
                SalesQuotation::query()
                    ->where('id', (int) $validated['quotation_id'])
                    ->where('franchisee_id', $franchiseeId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'converted',
                        'sales_invoice_id' => $invoice->id,
                    ]);
            }

            if (!empty($validated['override_audit_id'])) {
                PosOverrideAudit::query()
                    ->where('id', (int) $validated['override_audit_id'])
                    ->where('status', 'approved')
                    ->update([
                        'sales_invoice_id' => $invoice->id,
                        'checkout_snapshot' => $validated['override_snapshot'] ?? null,
                        'status' => 'consumed',
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if (!empty($validated['override_cache_key'])) {
                Cache::forget((string) $validated['override_cache_key']);
            }

            // Dispatch event to trigger reorder suggestions and other post-sale hooks
            SaleCompleted::dispatch($invoice, $franchiseeId);

                return response()->json([
                    'success'  => true,
                    'bill_no'  => $invoice->bill_no,
                    'invoice_id' => $invoice->id,
                ]);
            });
        };

        $requestId = trim((string) ($validated['request_id'] ?? ''));
        if ($requestId === '') {
            return $processCheckout();
        }

        $cacheScope = "pos_checkout:{$franchiseeId}:{$user->id}";
        $doneKey = "{$cacheScope}:done:{$requestId}";

        $cachedPayload = Cache::get($doneKey);
        if (is_array($cachedPayload) && ($cachedPayload['success'] ?? false)) {
            return response()->json($cachedPayload);
        }

        $lock = Cache::lock("{$cacheScope}:lock:{$requestId}", 30);
        if (!$lock->get()) {
            abort(429, 'Checkout is already in progress for this request. Please wait a moment.');
        }

        try {
            $response = $processCheckout();
            $payload = $response->getData(true);
            if (($payload['success'] ?? false) === true) {
                Cache::put($doneKey, $payload, now()->addMinutes(10));
            }
            return $response;
        } finally {
            optional($lock)->release();
        }
    }

    // ─────────────────────────────────────────────────────
    //  SALES RETURN
    // ─────────────────────────────────────────────────────

    /**
     * Process a POS return (partial or full bill).
     * Updates item qty/price on the original invoice, adds stock back.
     * Legacy: returnSaleData()
     */
    public function processReturn(Request $request, InventoryService $inventoryService, LedgerService $ledgerService)
    {
        $validated = $request->validate([
            'original_bill_no'          => 'required|string|exists:sales_invoices,bill_no',
            'refund_mode'               => 'nullable|string|in:cash,bank,adjust_in_wallet',
            'reason'                    => 'nullable|string|max:500',
            'override_request_id'       => 'required|string|max:80',
            'override_token'            => 'required|string|max:120',
            'override_reason'           => 'required|string|max:160',
            'override_snapshot'         => 'required|array',
            'override_snapshot.item_count' => ['required', 'integer', 'min:1'],
            'override_snapshot.max_line_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_snapshot.bill_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_snapshot.total_amount' => ['required', 'numeric', 'min:0'],
            'items'                      => 'required|array|min:1',
            'items.*.sales_invoice_item_id' => 'required|integer|exists:sales_invoice_items,id',
            'items.*.return_qty'         => 'required|numeric|min:0.01',
        ]);

        $cashier = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($cashier);

        $approval = $this->resolveOverrideApproval(
            franchiseeId: $franchiseeId,
            cashierUserId: (int) $cashier->id,
            token: (string) ($validated['override_token'] ?? ''),
            requestId: (string) ($validated['override_request_id'] ?? ''),
            action: 'return_override'
        );

        if (!$approval) {
            abort(422, 'Supervisor approval required to process POS returns.');
        }

        $approvedReason = trim((string) ($approval['reason'] ?? ''));
        $providedReason = trim((string) ($validated['override_reason'] ?? ''));
        if ($providedReason === '' || strcasecmp($providedReason, $approvedReason) !== 0) {
            abort(422, 'Supervisor reason mismatch. Please request approval again.');
        }

        $approvedSnapshot = $this->normalizeOverrideSnapshot($approval['approval_snapshot'] ?? null);
        $providedSnapshot = $this->normalizeOverrideSnapshot($validated['override_snapshot'] ?? null);
        if (!$approvedSnapshot || !$providedSnapshot || $approvedSnapshot !== $providedSnapshot) {
            abort(422, 'Supervisor approval no longer matches this return payload. Please re-approve.');
        }

        $validated['override_cache_key'] = !empty($approval['__cache_key']) ? (string) $approval['__cache_key'] : null;
        $validated['override_audit_id'] = !empty($approval['audit_id']) ? (int) $approval['audit_id'] : null;

        return DB::transaction(function () use ($validated, $inventoryService, $franchiseeId, $request, $ledgerService) {
            $original = SalesInvoice::where('bill_no', $validated['original_bill_no'])
                ->where('franchisee_id', $franchiseeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($original->status === 'cancelled') {
                abort(422, 'Cancelled invoice cannot be returned.');
            }

            $refundMode = (string) ($validated['refund_mode'] ?? 'cash');
            $reason = (string) ($validated['reason'] ?? 'POS counter return');

            $franchisee = Franchisee::query()->findOrFail($franchiseeId);
            $returnNo = $this->generateReturnNo($franchisee->shop_code ?? 'SHOP');

            $salesReturn = SalesReturn::create([
                'return_no' => $returnNo,
                'sales_invoice_id' => $original->id,
                'franchisee_id' => $franchiseeId,
                'user_id' => $request->user()->id,
                'customer_id' => $original->customer_id,
                'return_date' => now()->toDateString(),
                'reason' => $reason,
                'total_refund_amount' => 0,
                'refund_mode' => $refundMode,
            ]);

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

                $product = Product::query()->find($lineItem->product_id);
                $conversionFactor = $this->normalizeConversionFactor($product?->conversion_factor ?? 1);

                $originalQty = (float) $lineItem->qty;
                $originalFreeQty = (float) ($lineItem->free_qty ?? 0);
                $perUnitBase = $originalQty > 0 ? round(((float) $lineItem->rate * $originalQty) / $originalQty, 4) : 0.0;
                $perUnitDiscount = $originalQty > 0 ? round((float) $lineItem->discount_amount / $originalQty, 4) : 0.0;
                $perUnitTaxable = $originalQty > 0 ? round((float) $lineItem->taxable_amount / $originalQty, 4) : 0.0;
                $perUnitTax = $originalQty > 0 ? round((float) $lineItem->gst_amount / $originalQty, 4) : 0.0;
                $perUnitTotal = $originalQty > 0 ? round((float) $lineItem->total_amount / $originalQty, 4) : 0.0;

                $returnFreeQty = $originalQty > 0
                    ? round(($originalFreeQty / $originalQty) * (float) $returnQty, 4)
                    : 0.0;
                $returnStockQty = $this->toStockUnits(((float) $returnQty + $returnFreeQty), $conversionFactor);

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
                $remainingFreeQty = round(max(0, $originalFreeQty - $returnFreeQty), 4);

                if ($remainingQty <= 0) {
                    $lineItem->delete();
                } else {
                    $lineItem->update([
                        'qty' => $remainingQty,
                        'free_qty' => $remainingFreeQty,
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
                    'qty'           => $returnStockQty,
                    'rate'          => $lineItem->rate,
                    'reference_id'  => $salesReturn->id,
                    'created_by'    => $request->user()->id,
                ]);

                SalesReturnItem::create([
                    'sales_return_id' => $salesReturn->id,
                    'product_id' => $lineItem->product_id,
                    'batch_no' => $lineItem->batch_no,
                    'qty' => $returnQty,
                    'rate' => $lineItem->rate,
                    'gst_percent' => $lineItem->gst_percent,
                    'refund_amount' => $lineTotalReturn,
                    'status' => 'restocked',
                ]);
            }

            if ($returnedTotal <= 0) {
                abort(422, 'No returnable quantity found for selected lines.');
            }

            $approvedSnapshot = $this->normalizeOverrideSnapshot($validated['override_snapshot'] ?? null) ?? [];
            $derivedSnapshot = [
                'item_count' => (int) count($validated['items'] ?? []),
                'max_line_discount' => 0.0,
                'bill_discount_percent' => 0.0,
                'total_amount' => round((float) $returnedTotal, 2),
            ];

            if (($approvedSnapshot['item_count'] ?? 0) !== $derivedSnapshot['item_count'] ||
                abs((float) ($approvedSnapshot['total_amount'] ?? 0) - (float) $derivedSnapshot['total_amount']) > 0.01) {
                abort(422, 'Supervisor approval no longer matches the final return amount. Please re-approve.');
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

            $payment = SalePayment::query()
                ->where('sales_invoice_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($payment) {
                $cash = round((float) $payment->cash_amount, 2);
                $bank = round((float) $payment->bank_amount, 2);
                $credit = round((float) $payment->credit_amount, 2);

                $remainingAdjustment = round($returnedTotal, 2);
                $creditAdjusted = round(min($credit, $remainingAdjustment), 2);
                $credit = round($credit - $creditAdjusted, 2);
                $remainingAdjustment = round($remainingAdjustment - $creditAdjusted, 2);

                if ($remainingAdjustment > 0.01) {
                    if ($refundMode === 'cash') {
                        if ($remainingAdjustment - $cash > 0.01) {
                            abort(422, 'Cash refund exceeds cash received on this invoice. Use bank/wallet adjustment for remainder.');
                        }
                        $cash = round($cash - $remainingAdjustment, 2);
                    } elseif ($refundMode === 'bank') {
                        if ($remainingAdjustment - $bank > 0.01) {
                            abort(422, 'Bank refund exceeds bank amount received on this invoice. Use cash/wallet adjustment for remainder.');
                        }
                        $bank = round($bank - $remainingAdjustment, 2);
                    } else {
                        $cashAdjust = round(min($cash, $remainingAdjustment), 2);
                        $cash = round($cash - $cashAdjust, 2);
                        $remainingAfterCash = round($remainingAdjustment - $cashAdjust, 2);
                        $bankAdjust = round(min($bank, $remainingAfterCash), 2);
                        $bank = round($bank - $bankAdjust, 2);

                        if ($remainingAfterCash - $bankAdjust > 0.01) {
                            abort(422, 'Return exceeds settled amount on this invoice.');
                        }
                    }
                }

                $payment->update([
                    'cash_amount' => max(0, $cash),
                    'bank_amount' => max(0, $bank),
                    'credit_amount' => max(0, $credit),
                ]);
            }

            $salesReturn->update([
                'total_refund_amount' => round($returnedTotal, 2),
                'refund_mode' => $refundMode,
            ]);

            $ledgerService->recordEntry(
                ledgerable: $franchisee,
                transactionType: 'POS_RETURN',
                debit: round($returnedTotal, 2),
                credit: 0,
                reference: $salesReturn,
                paymentMode: $refundMode,
                narration: "POS return [{$salesReturn->return_no}] against {$original->bill_no}",
                transactionDate: now()->toDateString(),
            );

            if (!empty($validated['override_audit_id'])) {
                PosOverrideAudit::query()
                    ->where('id', (int) $validated['override_audit_id'])
                    ->where('status', 'approved')
                    ->update([
                        'sales_invoice_id' => $original->id,
                        'checkout_snapshot' => $validated['override_snapshot'] ?? null,
                        'status' => 'consumed',
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if (!empty($validated['override_cache_key'])) {
                Cache::forget((string) $validated['override_cache_key']);
            }

            return response()->json([
                'success'       => true,
                'return_amount' => round($returnedTotal, 2),
                'return_no' => $salesReturn->return_no,
                'refund_mode' => $refundMode,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────
    //  HELPER
    // ─────────────────────────────────────────────────────

    private function resolveFranchiseeId($user): int
    {
        $franchiseeId = method_exists($user, 'getEffectiveFranchiseeId')
            ? $user->getEffectiveFranchiseeId()
            : ($user->franchisee_id ?? null);

        if ($franchiseeId) {
            return (int) $franchiseeId;
        }

        abort(403, 'POS access requires an active franchise context.');
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

    private function assertCheckoutPaymentValidity(array $validated, float $invoiceTotal, ?int $customerId): void
    {
        $mode = (string) $validated['payment_mode'];
        $cash = round((float) $validated['cash_amount'], 2);
        $bank = round((float) $validated['bank_amount'], 2);
        $credit = round((float) $validated['credit_amount'], 2);
        $splitTotal = round($cash + $bank + $credit, 2);

        if (abs($splitTotal - $invoiceTotal) > 0.01) {
            abort(422, "Payment split total {$splitTotal} does not match computed invoice total {$invoiceTotal}.");
        }

        $needsTxnNo = in_array($mode, ['bank', 'bankCredit', 'cashBank'], true) || $bank > 0;
        if ($needsTxnNo && empty(trim((string) ($validated['transaction_no'] ?? '')))) {
            abort(422, 'Transaction number is required for bank/UPI payment components.');
        }

        $needsCustomer = in_array($mode, ['credit', 'cashCredit', 'bankCredit'], true) || $credit > 0;
        if ($needsCustomer && !$customerId) {
            abort(422, 'Customer is required for credit-based POS payment.');
        }

        if ($mode === 'cash' && (abs($cash - $invoiceTotal) > 0.01 || $bank > 0 || $credit > 0)) {
            abort(422, 'Cash mode must have full amount in cash only.');
        }

        if ($mode === 'bank' && (abs($bank - $invoiceTotal) > 0.01 || $cash > 0 || $credit > 0)) {
            abort(422, 'Bank mode must have full amount in bank/UPI only.');
        }

        if ($mode === 'credit' && (abs($credit - $invoiceTotal) > 0.01 || $cash > 0 || $bank > 0)) {
            abort(422, 'Credit mode must have full amount in credit only.');
        }

        if ($mode === 'cashCredit' && ($cash <= 0 || $credit <= 0 || $bank > 0)) {
            abort(422, 'Cash+Credit mode requires positive cash and credit values only.');
        }

        if ($mode === 'bankCredit' && ($bank <= 0 || $credit <= 0 || $cash > 0)) {
            abort(422, 'Bank+Credit mode requires positive bank and credit values only.');
        }

        if ($mode === 'cashBank' && ($cash <= 0 || $bank <= 0 || $credit > 0)) {
            abort(422, 'Cash+Bank mode requires positive cash and bank values only.');
        }
    }

    private function resolveRoundOffPreferences($user): array
    {
        $enabled = (bool) data_get($user->preferences, 'round_off_enabled', true);
        $mode = strtolower((string) data_get($user->preferences, 'round_off_mode', 'nearest'));

        if (!in_array($mode, ['nearest', 'up', 'down', 'none'], true)) {
            $mode = 'nearest';
        }

        if (!$enabled || $mode === 'none') {
            return [
                'enabled' => false,
                'mode' => 'none',
            ];
        }

        return [
            'enabled' => true,
            'mode' => $mode,
        ];
    }

    private function resolvePosSettings($user): array
    {
        return [
            'round_off_enabled' => (bool) data_get($user->preferences, 'round_off_enabled', true),
            'round_off_mode' => data_get($user->preferences, 'round_off_mode', 'nearest'),
            'supervisor_override_enabled' => (bool) data_get($user->preferences, 'supervisor_override_enabled', true),
            'supervisor_override_discount_threshold' => (float) data_get($user->preferences, 'supervisor_override_discount_threshold', 15),
            'receipt_layout' => data_get($user->preferences, 'receipt_layout', 'thermal'),
            'auto_print_after_checkout' => (bool) data_get($user->preferences, 'auto_print_after_checkout', true),
            'printer_paper_width' => data_get($user->preferences, 'printer_paper_width', '80mm'),
            'print_copies' => (int) data_get($user->preferences, 'print_copies', 1),
            'printer_name' => data_get($user->preferences, 'printer_name', ''),
            'bill_logo_url' => data_get($user->preferences, 'bill_logo_url', ''),
            'bill_header_line_1' => data_get($user->preferences, 'bill_header_line_1', ''),
            'bill_header_line_2' => data_get($user->preferences, 'bill_header_line_2', ''),
            'csv_format' => data_get($user->preferences, 'csv_format', 'marg'),
            'auto_open_invoice_after_checkout' => (bool) data_get($user->preferences, 'auto_open_invoice_after_checkout', true),
            'auto_lock_bill_on_hold' => (bool) data_get($user->preferences, 'auto_lock_bill_on_hold', false),
            'smart_batch_suggestion' => (bool) data_get($user->preferences, 'smart_batch_suggestion', true),
        ];
    }

    private function resolveSupervisorOverrideDiscountThreshold($user): float
    {
        $enabled = (bool) data_get($user->preferences, 'supervisor_override_enabled', true);
        if (!$enabled) {
            return 100.0;
        }

        $threshold = (float) data_get($user->preferences, 'supervisor_override_discount_threshold', 15);
        if ($threshold < 0) {
            $threshold = 0;
        }
        if ($threshold > 100) {
            $threshold = 100;
        }

        return round($threshold, 2);
    }

    private function requiresSupervisorOverride(array $validated, float $threshold): bool
    {
        $billDiscount = (float) ($validated['bill_discount_percent'] ?? 0);
        if ($billDiscount > $threshold) {
            return true;
        }

        foreach (($validated['items'] ?? []) as $item) {
            if ((float) ($item['discount_percent'] ?? 0) > $threshold) {
                return true;
            }
        }

        return false;
    }

    private function resolveOverrideApproval(int $franchiseeId, int $cashierUserId, string $token, string $requestId, string $action): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $key = $this->overrideApprovalCacheKey($franchiseeId, $cashierUserId, $token);
        $payload = Cache::get($key);

        if (!is_array($payload)) {
            return null;
        }

        if (($payload['action'] ?? null) !== $action) {
            return null;
        }

        if (($payload['request_id'] ?? null) !== $requestId) {
            return null;
        }

        if ((int) ($payload['cashier_user_id'] ?? 0) !== $cashierUserId) {
            return null;
        }

        $payload['__cache_key'] = $key;

        return $payload;
    }

    private function overrideApprovalCacheKey(int $franchiseeId, int $cashierUserId, string $token): string
    {
        return 'pos_override:' . $franchiseeId . ':' . $cashierUserId . ':' . hash('sha256', $token);
    }

    private function isSupervisorOverrideRoleAllowed(User $user): bool
    {
        return $user->hasErpRole(['Super Admin', 'Admin', 'Franchisee', 'Account']);
    }

    private function assertHoldEditorAccess(PosHold $hold, int $userId): void
    {
        $lockOwnerId = (int) ($hold->lock_owner_user_id ?? 0);
        $lockExpiresAt = $hold->lock_expires_at;
        $isActiveLock = $lockOwnerId > 0 && $lockExpiresAt && $lockExpiresAt->isFuture();

        if ($isActiveLock && $lockOwnerId !== $userId) {
            abort(409, 'This hold is currently being edited by another counter. Retry after lock expiry.');
        }
    }

    private function normalizeEstimatePayload(Request $request): array
    {
        $validated = $request->validate([
            'tab_code' => ['nullable', 'string', 'max:12'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.batch_no' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'items.*.gst_percent' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_amount' => ['required', 'numeric', 'min:0'],
            'totals' => ['required', 'array'],
            'totals.sub_total' => ['required', 'numeric', 'min:0'],
            'totals.discount_total' => ['required', 'numeric', 'min:0'],
            'totals.tax_amount' => ['required', 'numeric', 'min:0'],
            'totals.other_charges' => ['nullable', 'numeric', 'min:0'],
            'totals.round_off' => ['nullable', 'numeric'],
            'totals.total' => ['required', 'numeric', 'min:0'],
        ]);

        $validated['totals']['other_charges'] = (float) ($validated['totals']['other_charges'] ?? 0);
        $validated['totals']['round_off'] = (float) ($validated['totals']['round_off'] ?? 0);

        return $validated;
    }

    private function findActiveShift(int $franchiseeId, int $userId): ?PosShift
    {
        return PosShift::query()
            ->where('franchisee_id', $franchiseeId)
            ->where('user_id', $userId)
            ->where('status', self::SHIFT_STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    private function requireActiveShift(int $franchiseeId, int $userId): void
    {
        if (!$this->findActiveShift($franchiseeId, $userId)) {
            abort(422, 'Open shift is required before checkout. Please open shift from POS header.');
        }
    }

    private function computeShiftSummary(int $franchiseeId, int $userId, $openedAt, $closedAt): array
    {
        $aggregate = SalePayment::query()
            ->join('sales_invoices as si', 'si.id', '=', 'sale_payments.sales_invoice_id')
            ->where('si.franchisee_id', $franchiseeId)
            ->where('si.user_id', $userId)
            ->where('si.status', 'completed')
            ->whereBetween('si.date_time', [$openedAt, $closedAt])
            ->selectRaw('COUNT(DISTINCT si.id) as bill_count')
            ->selectRaw('COALESCE(SUM(si.total_amount), 0) as sales_total')
            ->selectRaw('COALESCE(SUM(sale_payments.cash_amount), 0) as cash_sales')
            ->selectRaw('COALESCE(SUM(sale_payments.bank_amount), 0) as bank_sales')
            ->selectRaw('COALESCE(SUM(sale_payments.credit_amount), 0) as credit_sales')
            ->first();

        return [
            'bill_count' => (int) ($aggregate->bill_count ?? 0),
            'sales_total' => round((float) ($aggregate->sales_total ?? 0), 2),
            'cash_sales' => round((float) ($aggregate->cash_sales ?? 0), 2),
            'bank_sales' => round((float) ($aggregate->bank_sales ?? 0), 2),
            'credit_sales' => round((float) ($aggregate->credit_sales ?? 0), 2),
        ];
    }

    private function serializeShift(?PosShift $shift): ?array
    {
        if (!$shift) {
            return null;
        }

        return [
            'id' => (int) $shift->id,
            'shift_no' => (string) $shift->shift_no,
            'status' => (string) $shift->status,
            'opening_cash' => round((float) ($shift->opening_cash ?? 0), 2),
            'closing_cash' => $shift->closing_cash !== null ? round((float) $shift->closing_cash, 2) : null,
            'expected_cash' => $shift->expected_cash !== null ? round((float) $shift->expected_cash, 2) : null,
            'cash_variance' => $shift->cash_variance !== null ? round((float) $shift->cash_variance, 2) : null,
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'summary' => is_array($shift->summary_payload) ? $shift->summary_payload : null,
        ];
    }

    private function generateShiftNo(string $shopCode): string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $candidate = 'SHIFT-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(3));
            if (!PosShift::where('shift_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'SHIFT-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    private function normalizeOverrideSnapshot($snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        return [
            'item_count' => (int) ($snapshot['item_count'] ?? 0),
            'max_line_discount' => round((float) ($snapshot['max_line_discount'] ?? 0), 2),
            'bill_discount_percent' => round((float) ($snapshot['bill_discount_percent'] ?? 0), 2),
            'total_amount' => round((float) ($snapshot['total_amount'] ?? 0), 2),
        ];
    }

    private function deriveCheckoutOverrideSnapshot(array $validated): array
    {
        $maxLineDiscount = 0.0;
        foreach (($validated['items'] ?? []) as $item) {
            $maxLineDiscount = max($maxLineDiscount, (float) ($item['discount_percent'] ?? 0));
        }

        return [
            'item_count' => (int) count($validated['items'] ?? []),
            'max_line_discount' => round($maxLineDiscount, 2),
            'bill_discount_percent' => round((float) ($validated['bill_discount_percent'] ?? 0), 2),
            'total_amount' => round((float) ($validated['total_amount'] ?? 0), 2),
        ];
    }

    private function calculateRoundOff(float $grossTotal, array $roundingConfig): array
    {
        $gross = round($grossTotal, 2);

        if (($roundingConfig['enabled'] ?? false) !== true || ($roundingConfig['mode'] ?? 'none') === 'none') {
            return [
                'gross' => $gross,
                'total' => $gross,
                'round_off' => 0.0,
            ];
        }

        $mode = (string) ($roundingConfig['mode'] ?? 'nearest');
        if ($mode === 'up') {
            $rounded = (float) ceil($gross);
        } elseif ($mode === 'down') {
            $rounded = (float) floor($gross);
        } else {
            $rounded = (float) round($gross, 0);
        }

        $roundOff = round($rounded - $gross, 2);

        return [
            'gross' => $gross,
            'total' => round($rounded, 2),
            'round_off' => $roundOff,
        ];
    }

    private function generateReturnNo(string $shopCode): string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $candidate = 'SR-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(3));
            if (!SalesReturn::where('return_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'SR-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    private function generateHoldNo(string $shopCode): string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $candidate = 'HOLD-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(3));
            if (!PosHold::where('hold_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'HOLD-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    private function generateQuotationNo(string $shopCode): string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $candidate = 'QT-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(3));
            if (!SalesQuotation::where('quotation_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'QT-' . strtoupper($shopCode) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    private function normalizeConversionFactor($value): float
    {
        $factor = (float) $value;

        return $factor > 0 ? $factor : 1.0;
    }

    private function toStockUnits(float $saleUnits, float $conversionFactor): float
    {
        return round($saleUnits / $conversionFactor, 4);
    }
}
