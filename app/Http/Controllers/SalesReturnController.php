<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->franchisee_id) {
            abort(403);
        }

        $returns = SalesReturn::with(['customer', 'items.product'])
            ->where('franchisee_id', $user->franchisee_id)
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

    public function store(Request $request, InventoryService $inventoryService)
    {
        $user = $request->user();
        if (!$user->franchisee_id) { abort(403); }

        $validated = $request->validate([
            'reason' => 'required|string',
            'refund_mode' => 'required|string|in:cash,bank,adjust_in_wallet',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.rate' => 'required|numeric',
            'items.*.gst_percent' => 'required|numeric',
            'items.*.refund_amount' => 'required|numeric',
            'items.*.status' => 'required|in:restocked,damaged',
        ]);

        return DB::transaction(function () use ($validated, $user, $inventoryService) {
            $totalRefund = array_sum(array_column($validated['items'], 'refund_amount'));
            $returnNo = 'SR-' . $user->franchisee->shop_code . '-' . date('YmdHis');

            $salesReturn = SalesReturn::create([
                'return_no' => $returnNo,
                'franchisee_id' => $user->franchisee_id,
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
                    $inventoryService->recordTransaction(
                        product_id: $item['product_id'],
                        batch_no: $item['batch_no'],
                        location_type: 'franchisee',
                        location_id: $user->franchisee_id,
                        transaction_type: 'POS_RETURN', // Positive qty adds it back
                        qty: $item['qty'],
                        reference_id: $salesReturn->id,
                        reference_type: SalesReturn::class,
                        remarks: "Customer Return [$returnNo]"
                    );
                }
            }

            return redirect()->route('pos.returns.index')->with('success', "Sales Return $returnNo recorded.");
        });
    }
}
