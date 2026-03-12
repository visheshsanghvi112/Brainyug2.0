<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\InventoryService;
use App\Services\LedgerService;

class OrderController extends Controller
{
    /**
     * Get B2B Order history for franchisee via Mobile
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $limit = $request->input('limit', 20);
        $franchiseeId = $user->getEffectiveFranchiseeId();

        $query = DistOrder::with('items.product:id,product_name');

        if ($user->hasRole('Franchisee') || $user->hasRole('Franchisee Staff')) {
            if (!$franchiseeId) {
                return response()->json(['error' => 'No franchisee profile active.'], 403);
            }
            $query->where('franchisee_id', $franchiseeId);
        }

        $orders = $query->latest('order_date')->paginate($limit);

        return response()->json($orders);
    }

    /**
     * Show detailed B2B order
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $order = DistOrder::with('items.product')->findOrFail($id);

        if ($user->hasRole('Franchisee') && $order->franchisee_id !== $user->getEffectiveFranchiseeId()) {
            return response()->json(['error' => 'Unauthorized access to order.'], 403);
        }

        return response()->json($order);
    }

    /**
     * Checkout Cart / Place B2B Order from Mobile
     * Example Body: {"items": [{"product_id": 5, "qty": 100}, {"product_id": 2, "qty": 50}], "remarks": "Rush!"}
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'remarks' => 'nullable|string'
        ]);

        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            return response()->json(['error' => 'Only Franchisees can place B2B orders.'], 403);
        }

        try {
            DB::beginTransaction();

            $order = DistOrder::create([
                'order_no' => 'MOB-' . date('Ymd') . '-' . rand(1000, 9999),
                'franchisee_id' => $franchiseeId,
                'created_by' => $user->id,
                'order_date' => now(),
                'status' => 'pending',
                'remarks' => $request->remarks,
                'total_amount' => 0 // Recalculated below
            ]);

            $total = 0;

            foreach ($request->items as $item) {
                // Fetch product for pricing
                $product = \App\Models\Product::findOrFail($item['product_id']);
                
                // Typical FMS uses PTR (Price to Retailer)
                $rate = $product->ptr;
                $amount = $rate * $item['qty'];
                $total += $amount;

                DistOrderItem::create([
                    'dist_order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'rate' => $rate,
                    'amount' => $amount
                ]);
            }

            $order->update(['total_amount' => $total]);

            DB::commit();

            return response()->json([
                'message' => 'Order placed successfully.',
                'order_no' => $order->order_no,
                'id' => $order->id
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to process order.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Complete POS checkout natively from the Mobile App
     * Uses InventoryService directly.
     */
    public function posSale(Request $request, InventoryService $inventoryService, LedgerService $ledgerService)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.batch_no' => 'required',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.rate' => 'required|numeric',
            'payments' => 'nullable|array', // Structure for cash/bank split
        ]);

        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            return response()->json(['error' => 'No active franchisee for this user.'], 403);
        }

        try {
            DB::beginTransaction();

            // POS Invoice creation
            $invoice = \App\Models\SalesInvoice::create([
                'bill_no' => 'MOB-' . date('ymd') . rand(1000, 9999), // Example UUID or sequence
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'date_time' => now(),
                'sub_total' => 0,
                'status' => 'completed'
            ]);

            $total = 0;

            foreach ($request->items as $item) {
                // Check stock validity directly via Ledger Service
                $inventoryService->recordSale(
                    'franchisee',
                    $franchiseeId,
                    $item['product_id'],
                    $item['batch_no'],
                    $item['qty'],
                    $item['rate'], // Example param signature matching our service
                    $user->id,
                    "Mobile POS Sale Vol-{$invoice->bill_no}"
                );

                $amount = $item['qty'] * $item['rate'];
                $total += $amount;

                \App\Models\SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'batch_no' => $item['batch_no'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                    'mrp' => 0, // Mocked for speed
                    'taxable_amount' => $amount,
                    'gst_percent' => 5, // Mocked
                    'gst_amount' => $amount * 0.05,
                    'total_amount' => $amount * 1.05
                ]);
            }

            $invoice->update(['total_amount' => $total * 1.05]);

            // Clear the 60-second dashboard cache to force refresh on next pull
            Cache::forget("api.stock.franchisee.{$franchiseeId}");

            DB::commit();

            return response()->json([
                'message' => 'Retail API Sale Recorded',
                'bill_no' => $invoice->bill_no,
                'total' => $invoice->total_amount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'API Transaction failed: ' . $e->getMessage()], 422);
        }
    }
}
