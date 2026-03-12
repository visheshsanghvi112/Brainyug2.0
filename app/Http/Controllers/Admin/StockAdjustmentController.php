<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Franchisee;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasRole(['Super Admin', 'Distributor'])) {
            abort(403);
        }

        $products = Product::where('is_active', true)
            ->select('id', 'product_name', 'sku')
            ->orderBy('product_name')
            ->get();

        $locations = collect([['type' => 'warehouse', 'id' => 0, 'label' => 'HO Warehouse']]);
        $franchisees = Franchisee::where('status', 'active')->select('id', 'shop_name', 'shop_code')->get();
        foreach ($franchisees as $f) {
            $locations->push(['type' => 'franchisee', 'id' => $f->id, 'label' => "{$f->shop_name} ({$f->shop_code})"]);
        }

        return Inertia::render('Stock/Adjust', [
            'products'  => $products,
            'locations' => $locations,
        ]);
    }

    public function store(Request $request, InventoryService $inventoryService)
    {
        if (!$request->user()->hasRole(['Super Admin', 'Distributor'])) {
            abort(403);
        }

        $validated = $request->validate([
            'product_id'    => 'required|exists:products,id',
            'batch_no'      => 'required|string|max:50',
            'exp_date'      => 'nullable|date',
            'location_type' => 'required|in:warehouse,franchisee',
            'location_id'   => 'required|integer|min:0',
            'adjustment_type' => 'required|in:add,remove',
            'qty'           => 'required|integer|min:1',
            'reason'        => 'required|string|max:500',
            'unit_cost'     => 'nullable|numeric|min:0',
        ]);

        // Verify location makes sense
        if ($validated['location_type'] === 'franchisee' && $validated['location_id'] > 0) {
            abort_unless(Franchisee::where('id', $validated['location_id'])->where('status', 'active')->exists(), 422, 'Invalid franchisee location.');
        }

        $qty = $validated['adjustment_type'] === 'add'
            ? abs($validated['qty'])
            : -abs($validated['qty']);

        $inventoryService->recordAdjustment([
            'product_id'   => $validated['product_id'],
            'batch_no'     => $validated['batch_no'],
            'expiry_date'  => $validated['exp_date'],
            'location_type'=> $validated['location_type'],
            'location_id'  => $validated['location_id'],
            'qty'          => $qty,
            'rate'         => $validated['unit_cost'] ?? 0,
            'remarks'      => $validated['reason'],
            'created_by'   => $request->user()->id,
        ]);

        return back()->with('success', 'Stock adjusted successfully. Inventory ledger updated.');
    }
}
