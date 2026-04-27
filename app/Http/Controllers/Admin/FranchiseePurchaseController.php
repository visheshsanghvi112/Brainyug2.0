<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchisee;
use App\Models\FranchiseePurchase;
use App\Models\FranchiseePurchaseItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\FranchiseePurchaseService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FranchiseePurchaseController extends Controller
{
    public function __construct(
        private FranchiseePurchaseService $franchiseePurchaseService
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = FranchiseePurchase::query()
            ->with(['franchisee:id,shop_name,shop_code,shop_type', 'supplier:id,name,code', 'createdBy:id,name', 'approvedBy:id,name'])
            ->latest('id');

        $this->applyVisibilityScope($query, $user);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($nested) use ($search) {
                $nested->where('transaction_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('franchisee', fn ($f) => $f->where('shop_name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->input('approval_status'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('franchisee_id')) {
            $query->where('franchisee_id', (int) $request->input('franchisee_id'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->input('supplier_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('purchase_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('purchase_date', '<=', $request->input('date_to'));
        }

        $purchases = $query->paginate(20)->withQueryString();

        return Inertia::render('Procurement/FranchiseePurchases/Index', [
            'purchases' => $purchases,
            'filters' => $request->only([
                'search',
                'approval_status',
                'status',
                'franchisee_id',
                'supplier_id',
                'date_from',
                'date_to',
            ]),
            'approvalStatuses' => ['pending', 'approved', 'rejected'],
            'statuses' => ['draft', 'completed', 'cancelled'],
            'franchisees' => $this->franchiseeOptions($user),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'canReview' => !$user->isFranchisee(),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Procurement/FranchiseePurchases/Create', [
            'franchisees' => $this->franchiseeOptions($user),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()
                ->visibleForFranchise()
                ->with('hsn:id,hsn_code,sgst_percent,cgst_percent,igst_percent')
                ->orderBy('product_name')
                ->get(['id', 'product_name', 'sku', 'mrp', 'ptr', 'rate_a', 'pts', 'unit', 'hsn_id']),
            'nextTransactionNumber' => FranchiseePurchase::previewNextTransactionNumber(),
            'currentFinancialYear' => FranchiseePurchase::currentFinancialYear(),
            'effectiveFranchiseeId' => $user->isFranchisee() ? $user->getEffectiveFranchiseeId() : null,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $isFranchisee = $user->isFranchisee();

        $validated = $request->validate([
            'franchisee_id' => ($isFranchisee ? 'nullable' : 'required') . '|exists:franchisees,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_date' => 'required|date',
            'received_date' => 'nullable|date|after_or_equal:purchase_date',
            'reason_code' => 'nullable|in:normal,urgent,spot',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.mfg_date' => 'nullable|date',
            'items.*.expiry_date' => 'required|date',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.gst_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($isFranchisee) {
            $franchiseeId = $user->getEffectiveFranchiseeId();
            if (!$franchiseeId) {
                abort(403, 'No franchisee profile linked to this account.');
            }
            $validated['franchisee_id'] = $franchiseeId;
        }

        $purchase = $this->franchiseePurchaseService->createDraft($validated, $user);

        return redirect()->route('admin.franchisee-purchases.show', $purchase)
            ->with('success', "Outside purchase {$purchase->transaction_number} created successfully.");
    }

    public function show(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        $franchiseePurchase->load([
            'franchisee:id,shop_name,shop_code,shop_type',
            'supplier:id,name,code',
            'createdBy:id,name',
            'approvedBy:id,name',
            'items.product:id,product_name,sku',
            'items.hsn:id,hsn_code',
        ]);

        return Inertia::render('Procurement/FranchiseePurchases/Show', [
            'purchase' => $franchiseePurchase,
            'canReview' => !$request->user()->isFranchisee(),
            'canApprove' => !$request->user()->isFranchisee() && $franchiseePurchase->canApprove(),
            'canReject' => !$request->user()->isFranchisee() && $franchiseePurchase->canReject(),
            'canCancel' => !$request->user()->isFranchisee() && $franchiseePurchase->status === 'completed',
            'canEdit' => $this->canEdit($request->user(), $franchiseePurchase),
        ]);
    }

    public function edit(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        if (!$this->canEdit($request->user(), $franchiseePurchase)) {
            abort(403, 'Only draft purchases can be edited.');
        }

        $franchiseePurchase->load(['items.product:id,product_name,sku', 'franchisee:id,shop_name,shop_code', 'supplier:id,name,code']);

        return Inertia::render('Procurement/FranchiseePurchases/Edit', [
            'purchase' => $franchiseePurchase,
            'franchisees' => $this->franchiseeOptions($request->user()),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()
                ->visibleForFranchise()
                ->with('hsn:id,hsn_code,sgst_percent,cgst_percent,igst_percent')
                ->orderBy('product_name')
                ->get(['id', 'product_name', 'sku', 'mrp', 'ptr', 'rate_a', 'pts', 'unit', 'hsn_id']),
        ]);
    }

    public function update(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        if (!$this->canEdit($request->user(), $franchiseePurchase)) {
            abort(403, 'Only draft purchases can be updated.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_date' => 'required|date',
            'received_date' => 'nullable|date|after_or_equal:purchase_date',
            'reason_code' => 'nullable|in:normal,urgent,spot',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.mfg_date' => 'nullable|date',
            'items.*.expiry_date' => 'required|date',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.mrp' => 'required|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.gst_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::transaction(function () use ($franchiseePurchase, $validated) {
            $franchiseePurchase->update([
                'supplier_id' => $validated['supplier_id'],
                'purchase_date' => $validated['purchase_date'],
                'received_date' => $validated['received_date'] ?? null,
                'reason_code' => $validated['reason_code'] ?? 'normal',
                'notes' => $validated['notes'] ?? null,
            ]);

            $franchiseePurchase->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $qty = (float) ($itemData['qty'] ?? 0);
                $rate = (float) ($itemData['rate'] ?? 0);
                $discountAmount = (float) ($itemData['discount_amount'] ?? 0);
                $gstPercent = (float) ($itemData['gst_percent'] ?? 0);

                $taxable = round(max(0, ($rate * $qty) - $discountAmount), 2);
                $gstAmount = round($taxable * ($gstPercent / 100), 2);

                FranchiseePurchaseItem::create([
                    'franchisee_purchase_id' => $franchiseePurchase->id,
                    'product_id' => $itemData['product_id'],
                    'batch_no' => $itemData['batch_no'],
                    'mfg_date' => $itemData['mfg_date'] ?? null,
                    'expiry_date' => $itemData['expiry_date'],
                    'qty' => $qty,
                    'free_qty' => (float) ($itemData['free_qty'] ?? 0),
                    'unit' => $itemData['unit'] ?? 'pcs',
                    'mrp' => (float) ($itemData['mrp'] ?? 0),
                    'rate' => $rate,
                    'discount_percent' => (float) ($itemData['discount_percent'] ?? 0),
                    'discount_amount' => $discountAmount,
                    'gst_percent' => $gstPercent,
                    'gst_amount' => $gstAmount,
                    'taxable_amount' => $taxable,
                    'total_amount' => round($taxable + $gstAmount, 2),
                ]);
            }

            $totals = $franchiseePurchase->items()
                ->selectRaw('COALESCE(SUM(taxable_amount),0) as subtotal, COALESCE(SUM(gst_amount),0) as total_gst, COALESCE(SUM(total_amount),0) as total_amount')
                ->first();

            $totalGst = (float) ($totals->total_gst ?? 0);

            $franchiseePurchase->update([
                'subtotal' => round((float) ($totals->subtotal ?? 0), 2),
                'sgst_amount' => round($totalGst / 2, 2),
                'cgst_amount' => round($totalGst / 2, 2),
                'igst_amount' => 0,
                'total_amount' => round((float) ($totals->total_amount ?? 0), 2),
            ]);
        });

        return redirect()->route('admin.franchisee-purchases.show', $franchiseePurchase)
            ->with('success', 'Outside purchase draft updated successfully.');
    }

    public function approve(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        if ($request->user()->isFranchisee()) {
            abort(403, 'Only HO/distributor roles can approve.');
        }

        try {
            $this->franchiseePurchaseService->approvePurchase($franchiseePurchase, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', "{$franchiseePurchase->transaction_number} approved and stock posted.");
    }

    public function reject(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        if ($request->user()->isFranchisee()) {
            abort(403, 'Only HO/distributor roles can reject.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        try {
            $this->franchiseePurchaseService->rejectPurchase($franchiseePurchase, $validated['rejection_reason'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', "{$franchiseePurchase->transaction_number} rejected.");
    }

    public function cancel(Request $request, FranchiseePurchase $franchiseePurchase)
    {
        $this->ensureVisibleToUser($request->user(), $franchiseePurchase);

        if ($request->user()->isFranchisee()) {
            abort(403, 'Only HO/distributor roles can cancel approved purchases.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $this->franchiseePurchaseService->cancelPurchase($franchiseePurchase, $validated['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', "{$franchiseePurchase->transaction_number} cancelled and inventory reversed.");
    }

    private function applyVisibilityScope($query, $user): void
    {
        if ($user->isFranchisee()) {
            $franchiseeId = $user->getEffectiveFranchiseeId();
            $query->where('franchisee_id', $franchiseeId ?: -1);
        }
    }

    private function ensureVisibleToUser($user, FranchiseePurchase $purchase): void
    {
        if (!$user->isFranchisee()) {
            return;
        }

        $franchiseeId = $user->getEffectiveFranchiseeId();
        if (!$franchiseeId || (int) $purchase->franchisee_id !== (int) $franchiseeId) {
            abort(403);
        }
    }

    private function canEdit($user, FranchiseePurchase $purchase): bool
    {
        if ($purchase->status !== 'draft' || $purchase->approval_status !== 'pending') {
            return false;
        }

        if ($user->isFranchisee()) {
            $franchiseeId = $user->getEffectiveFranchiseeId();
            return $franchiseeId && (int) $purchase->franchisee_id === (int) $franchiseeId;
        }

        return true;
    }

    private function franchiseeOptions($user)
    {
        if ($user->isFranchisee()) {
            $franchiseeId = $user->getEffectiveFranchiseeId();
            return Franchisee::query()
                ->whereKey($franchiseeId ?: 0)
                ->get(['id', 'shop_name', 'shop_code', 'shop_type']);
        }

        return Franchisee::query()
            ->active()
            ->orderBy('shop_name')
            ->get(['id', 'shop_name', 'shop_code', 'shop_type']);
    }
}
