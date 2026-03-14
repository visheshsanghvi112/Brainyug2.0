<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
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

        if ($user->isFranchisee()) {
            if (!$franchiseeId) {
                return response()->json(['error' => 'No franchisee profile active.'], 403);
            }
            $query->where('franchisee_id', $franchiseeId);
        }

        $orders = $query->latest()->paginate($limit);

        return response()->json($orders);
    }

    /**
     * Show detailed B2B order
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $order = DistOrder::with('items.product')->findOrFail($id);

        if ($user->isFranchisee() && $order->franchisee_id !== $user->getEffectiveFranchiseeId()) {
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
        $validated = $request->validate([
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

            $productMap = Product::query()
                ->visibleForFranchise()
                ->with('hsn:id,cgst_percent,sgst_percent,igst_percent')
                ->whereIn('id', collect($validated['items'])->pluck('product_id')->unique()->all())
                ->get(['id', 'hsn_id', 'mrp', 'rate_a', 'ptr', 'pts', 'sgst', 'cgst', 'igst'])
                ->keyBy('id');

            if ($productMap->count() !== count(collect($validated['items'])->pluck('product_id')->unique())) {
                return response()->json(['error' => 'One or more products are no longer available for franchise ordering.'], 422);
            }

            $order = DistOrder::create([
                'order_number' => DistOrder::generateOrderNumber(),
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'status' => 'pending',
                'notes' => $validated['remarks'] ?? null,
                'subtotal' => 0,
                'total_amount' => 0,
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($validated['items'] as $item) {
                $product = $productMap->get($item['product_id']);
                $rate = $product->franchiseRate();
                $gstPercent = $product->gstPercent();
                $taxableAmount = round($rate * $item['qty'], 2);
                $gstAmount = round($taxableAmount * ($gstPercent / 100), 2);
                $lineTotal = $taxableAmount + $gstAmount;

                $subtotal += $taxableAmount;
                $taxTotal += $gstAmount;

                DistOrderItem::create([
                    'dist_order_id' => $order->id,
                    'product_id' => $product->id,
                    'request_qty' => $item['qty'],
                    'mrp' => $product->mrp ?? 0,
                    'rate' => $rate,
                    'gst_percent' => $gstPercent,
                    'taxable_amount' => $taxableAmount,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $lineTotal,
                ]);
            }

            $order->update([
                'subtotal' => round($subtotal, 2),
                'sgst_amount' => round($taxTotal / 2, 2),
                'cgst_amount' => round($taxTotal / 2, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Order placed successfully.',
                'order_number' => $order->order_number,
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
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.expiry_date' => 'nullable|date',
            'payments' => 'nullable|array', // Structure for cash/bank split
        ]);

        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            return response()->json(['error' => 'No active franchisee for this user.'], 403);
        }

        try {
            DB::beginTransaction();

            $productMap = Product::query()
                ->visibleForFranchise()
                ->with('hsn:id,cgst_percent,sgst_percent,igst_percent')
                ->whereIn('id', collect($validated['items'])->pluck('product_id')->unique()->all())
                ->get(['id', 'hsn_id', 'mrp', 'rate_a', 'ptr', 'pts', 'sgst', 'cgst', 'igst'])
                ->keyBy('id');

            if ($productMap->count() !== count(collect($validated['items'])->pluck('product_id')->unique())) {
                return response()->json(['error' => 'One or more products are no longer available for franchise sale.'], 422);
            }

            $invoice = SalesInvoice::create([
                'bill_no' => 'MOB-' . date('ymd') . rand(1000, 9999), // Example UUID or sequence
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
                'date_time' => now(),
                'sub_total' => 0,
                'total_discount_amount' => 0,
                'total_tax_amount' => 0,
                'other_charges' => 0,
                'total_amount' => 0,
                'status' => 'completed'
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($validated['items'] as $item) {
                $product = $productMap->get($item['product_id']);
                $rate = $product->franchiseRate();
                $mrp = round((float) ($product->mrp ?? 0), 2);
                $gstPercent = $product->gstPercent();

                $availableStock = $inventoryService->getStock(
                    $product->id,
                    $item['batch_no'],
                    'franchisee',
                    $franchiseeId
                );

                if ($availableStock > 0 && (float) $item['qty'] > $availableStock) {
                    return response()->json([
                        'error' => "Insufficient stock for batch {$item['batch_no']}.",
                    ], 422);
                }

                $taxableAmount = round($rate * $item['qty'], 2);
                $gstAmount = round($taxableAmount * ($gstPercent / 100), 2);
                $lineTotal = $taxableAmount + $gstAmount;

                $subtotal += $taxableAmount;
                $taxTotal += $gstAmount;

                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'batch_no' => $item['batch_no'],
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'qty' => $item['qty'],
                    'free_qty' => 0,
                    'rate' => $rate,
                    'mrp' => $mrp,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'taxable_amount' => $taxableAmount,
                    'gst_percent' => $gstPercent,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $lineTotal,
                ]);

                $inventoryService->recordSale([
                    'product_id' => $item['product_id'],
                    'batch_no' => $item['batch_no'],
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'mrp' => $mrp,
                    'franchisee_id' => $franchiseeId,
                    'qty' => $item['qty'],
                    'rate' => $rate,
                    'reference_id' => $invoice->id,
                    'created_by' => $user->id,
                ]);
            }

            $invoice->update([
                'sub_total' => round($subtotal, 2),
                'total_tax_amount' => round($taxTotal, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
            ]);

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
