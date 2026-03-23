<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Franchisee;
use App\Models\Product;
use App\Services\LedgerService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        if (!$franchiseeId) {
            abort(403);
        }

        $returns = SalesReturn::with(['customer', 'items.product'])
            ->where('franchisee_id', $franchiseeId)
            ->latest()
            ->paginate(20);

        return Inertia::render('POS/Returns/Index', [
            'returns' => $returns
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $products = Product::where('is_active', true)->select('id', 'product_name', 'sku')->get();

        return Inertia::render('POS/Returns/Create', [
            'products' => $products
        ]);
    }

    public function store(Request $request, InventoryService $inventoryService, LedgerService $ledgerService)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        if (!$franchiseeId) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
            'refund_mode' => 'required|string|in:cash,bank,adjust_in_wallet',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric',
            'items.*.gst_percent' => 'required|numeric',
            'items.*.refund_amount' => 'required|numeric',
            'items.*.status' => 'required|in:restocked,damaged',
        ]);

        return DB::transaction(function () use ($validated, $user, $inventoryService, $ledgerService, $franchiseeId) {
            $totalRefund = array_sum(array_column($validated['items'], 'refund_amount'));
            $franchisee = Franchisee::query()->findOrFail($franchiseeId);
            $shopCode = strtoupper((string) ($franchisee->shop_code ?? 'SHOP'));
            $returnNo = 'SR-' . $shopCode . '-' . date('YmdHis') . '-' . strtoupper(Str::random(3));

            $salesReturn = SalesReturn::create([
                'return_no' => $returnNo,
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'return_date' => now(),
                'reason' => $validated['reason'],
                'total_refund_amount' => $totalRefund,
                'refund_mode' => $validated['refund_mode']
            ]);

            foreach ($validated['items'] as $item) {
                SalesReturnItem::create([
                    'sales_return_id' => $salesReturn->id,
                    'product_id' => $item['product_id'],
                    'batch_no' => $item['batch_no'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                    'gst_percent' => $item['gst_percent'],
                    'refund_amount' => $item['refund_amount'],
                    'status' => $item['status']
                ]);

                // Only restock if the product is not damaged
                if ($item['status'] === 'restocked') {
                    $inventoryService->recordSaleReturn([
                        'product_id' => (int) $item['product_id'],
                        'batch_no' => (string) $item['batch_no'],
                        'franchisee_id' => $franchiseeId,
                        'qty' => (float) $item['qty'],
                        'rate' => (float) $item['rate'],
                        'reference_id' => $salesReturn->id,
                        'created_by' => $user->id,
                    ]);
                }
            }

            $ledgerService->recordEntry(
                ledgerable: $franchisee,
                transactionType: 'POS_RETURN',
                debit: round((float) $totalRefund, 2),
                credit: 0,
                reference: $salesReturn,
                paymentMode: (string) $validated['refund_mode'],
                narration: "Manual sales return [{$returnNo}]",
                transactionDate: now()->toDateString(),
            );

            return redirect()->route('pos.returns.index')->with('success', "Sales Return $returnNo recorded.");
        });
    }

    private function resolveFranchiseeId($user): ?int
    {
        $franchiseeId = method_exists($user, 'getEffectiveFranchiseeId')
            ? $user->getEffectiveFranchiseeId()
            : ($user->franchisee_id ?? null);

        return $franchiseeId ? (int) $franchiseeId : null;
    }
}
