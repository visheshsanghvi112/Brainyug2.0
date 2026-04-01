<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Controller;
use App\Models\B2bCart;
use App\Models\B2bCartItem;
use App\Models\Product;
use App\Models\DistOrder;
use App\Services\DistOrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Replaces the legacy Place_order / New_arrived_products_cart / Web Services cart logic.
 * Gives Franchisees their specific B2B portal ordering tools.
 */
class CartController extends Controller
{
    public function __construct(
        private DistOrderWorkflowService $distOrderWorkflowService
    ) {}

    public function index(Request $request)
    {
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            abort(403, 'You must be linked to a franchisee to access the cart.');
        }

        $cart = B2bCart::firstOrCreate([
            'franchisee_id' => $franchiseeId,
            'user_id'       => $request->user()->id,
        ]);

        $this->syncCartRates($cart);

        $cart->load([
            'items.product' => fn ($query) => $query->with(['hsn:id,hsn_code', 'company:id,name', 'salt:id,name'])
                ->select('id', 'product_name', 'sku', 'mrp', 'rate_a', 'ptr', 'pts', 'hsn_id', 'company_id', 'salt_id', 'packing_desc', 'unit'),
        ]);

        return Inertia::render('B2b/Cart/Index', [
            'cart'     => $cart,
            'products' => Product::query()
                ->visibleForFranchise()
                ->with(['hsn:id,hsn_code', 'company:id,name', 'salt:id,name'])
                ->orderBy('product_name')
                ->get(['id', 'product_name', 'sku', 'rate_a', 'ptr', 'pts', 'mrp', 'hsn_id', 'company_id', 'salt_id', 'packing_desc', 'unit'])
                ->map(function (Product $product) {
                    $product->rate_a         = $product->franchiseRate();
                    $product->franchise_rate = $product->rate_a;

                    return $product;
                })
                ->values(),
        ]);
    }

    public function addToCart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty'        => 'required|numeric|min:1',
            'remark'     => 'nullable|string|max:255',
        ]);

        $product = Product::query()
            ->visibleForFranchise()
            ->findOrFail($validated['product_id']);

        $franchiseeId = $request->user()->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            abort(403, 'You must be linked to a franchisee to place a B2B order.');
        }

        $cart = B2bCart::firstOrCreate([
            'franchisee_id' => $franchiseeId,
            'user_id'       => $request->user()->id,
        ]);

        // Standard rate logic mapping to legacy `rate_a` for franchisees
        $rate = $product->franchiseRate();

        $cartItem     = $cart->items()->where('product_id', $product->id)->first();
        $requestedQty = (float) $validated['qty'];

        if ($cartItem) {
            $newQty  = round((float) $cartItem->qty + $requestedQty, 2);
            $freeQty = $this->calculateFreeQty($newQty);

            $cartItem->update([
                'qty'          => $newQty,
                'free_qty'     => $freeQty,
                'rate'         => $rate,
                'remark'       => $validated['remark'] ?? $cartItem->remark,
                'total_amount' => round($newQty * $rate, 2),
            ]);
        } else {
            $freeQty = $this->calculateFreeQty($requestedQty);

            $cart->items()->create([
                'product_id'   => $product->id,
                'qty'          => $requestedQty,
                'free_qty'     => $freeQty,
                'rate'         => $rate,
                'remark'       => $validated['remark'] ?? null,
                'total_amount' => round($requestedQty * $rate, 2),
            ]);
        }

        $this->updateCartTotals($cart);

        return back()->with('success', 'Product added to cart.');
    }

    // ── GAP FIX: inline quantity editing without remove-and-re-add ───────────
    public function updateQty(Request $request, B2bCartItem $item)
    {
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();
        $userId = $request->user()->id;

        if (
            !$franchiseeId
            || (int) $item->cart->franchisee_id !== (int) $franchiseeId
            || (int) $item->cart->user_id !== (int) $userId
        ) {
            abort(403);
        }

        $validated = $request->validate([
            'qty'    => 'required|numeric|min:1',
            'remark' => 'nullable|string|max:255',
        ]);

        $product = Product::query()
            ->visibleForFranchise()
            ->find($item->product_id);

        if (!$product) {
            $cart = $item->cart;
            $item->delete();
            $this->updateCartTotals($cart);

            return back()->with('error', 'This product is no longer available for franchise ordering and was removed from the cart.');
        }

        $newQty  = (float) $validated['qty'];
        $rate    = (float) $product->franchiseRate();
        $freeQty = $this->calculateFreeQty($newQty);

        $item->update([
            'qty'          => $newQty,
            'rate'         => $rate,
            'free_qty'     => $freeQty,
            'remark'       => array_key_exists('remark', $validated) ? $validated['remark'] : $item->remark,
            'total_amount' => round($newQty * $rate, 2),
        ]);

        $this->updateCartTotals($item->cart);

        return back()->with('success', 'Quantity updated.');
    }

    public function remove(Request $request, B2bCartItem $item)
    {
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();

        if (
            !$franchiseeId
            || (int) $item->cart->franchisee_id !== (int) $franchiseeId
            || (int) $item->cart->user_id !== (int) $request->user()->id
        ) {
            abort(403);
        }

        $cart = $item->cart;
        $item->delete();
        $this->updateCartTotals($cart);

        return back()->with('success', 'Item removed.');
    }

    public function checkout(Request $request)
    {
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            abort(403, 'You must be linked to a franchisee to place a B2B order.');
        }

        $cart = B2bCart::with('items.product.hsn')
            ->where('user_id', $request->user()->id)
            ->where('franchisee_id', $franchiseeId)
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return back()->with('error', 'Cart is empty. Add products to place an order.');
        }

        // Backend enforcement of the Rs 10,000 minimum order amount.
        // Sync rates first so the total is fresh before the check.
        $this->syncCartRates($cart);
        $cart->refresh();

        if ((float) $cart->total_amount < 10000) {
            return back()->with('error',
                'Minimum order value of Rs 10,000 required. Current total: Rs '
                . number_format((float) $cart->total_amount, 2)
            );
        }

        // Re-load with HSN for GST calc
        $cart->load('items.product.hsn');

        DB::transaction(function () use ($cart, $franchiseeId, $request) {
            $order = DistOrder::create([
                'order_number' => DistOrder::generateOrderNumber(),
                'franchisee_id' => $franchiseeId,
                'user_id'       => $request->user()->id,
                'status'        => 'pending',
                'subtotal'      => 0,
                'sgst_amount'   => 0,
                'cgst_amount'   => 0,
                'igst_amount'   => 0,
                'total_amount'  => 0,
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;

            /** @var B2bCartItem $item */
            foreach ($cart->items as $item) {
                $product = $item->product;

                if (!$product || !$product->is_active || $product->hide || $product->is_banned) {
                    abort(422, 'Cart contains product(s) no longer available for franchise ordering. Please refresh the cart.');
                }

                $currentRate   = $product->franchiseRate();
                $gst           = $product->gstPercent();
                $taxableAmount = round((float) $item->qty * $currentRate, 2);
                $gstAmount     = round($taxableAmount * ($gst / 100), 2);
                $lineTotal     = round($taxableAmount + $gstAmount, 2);

                $subtotal += $taxableAmount;
                $taxTotal += $gstAmount;

                $order->items()->create([
                    'product_id'   => $item->product_id,
                    'request_qty'  => $item->qty,
                    'free_qty'     => $item->free_qty ?? 0,
                    'rate'         => $currentRate,
                    'mrp'          => $product->mrp ?? 0,
                    'gst_percent'  => $gst,
                    'remark'       => $item->remark,
                    'taxable_amount' => $taxableAmount,
                    'gst_amount'   => $gstAmount,
                    'total_amount' => $lineTotal,
                ]);
            }

            $order->update([
                'subtotal'     => round($subtotal, 2),
                'sgst_amount'  => round($taxTotal / 2, 2),
                'cgst_amount'  => round($taxTotal / 2, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
            ]);

            $this->distOrderWorkflowService->logInitialSubmission($order, $request->user(), [
                'source'     => 'b2b_cart_checkout',
                'line_count' => $order->items()->count(),
            ]);

            // Flush cart after successful order creation
            $cart->items()->delete();
            $cart->update(['subtotal' => 0, 'total_amount' => 0]);
        });

        // Redirect to order history, not dashboard.
        return redirect()->route('admin.dist-orders.index')
            ->with('success', 'Order submitted successfully! HO will allocate batches and dispatch shortly.');
    }

    private function updateCartTotals(B2bCart $cart): void
    {
        $subtotal = $cart->items()->sum('total_amount');
        $cart->update([
            'subtotal'     => $subtotal,
            'total_amount' => $subtotal,
        ]);
    }

    private function syncCartRates(B2bCart $cart): void
    {
        $cart->loadMissing('items');

        $visibleProducts = Product::query()
            ->visibleForFranchise()
            ->whereIn('id', $cart->items->pluck('product_id')->unique()->all())
            ->get(['id', 'mrp', 'rate_a', 'ptr', 'pts'])
            ->keyBy('id');

        $updated = false;

        foreach ($cart->items as $item) {
            $product = $visibleProducts->get($item->product_id);

            if (!$product) {
                $item->delete();
                $updated = true;
                continue;
            }

            $currentRate     = $product->franchiseRate();
            $expectedTotal   = round((float) $item->qty * $currentRate, 2);
            $expectedFreeQty = $this->calculateFreeQty((float) $item->qty);

            if (
                (float) $item->rate !== $currentRate
                || round((float) $item->total_amount, 2) !== $expectedTotal
                || round((float) $item->free_qty, 2) !== $expectedFreeQty
            ) {
                $item->update([
                    'rate'         => $currentRate,
                    'total_amount' => $expectedTotal,
                    'free_qty'     => $expectedFreeQty,
                ]);
                $updated = true;
            }
        }

        if ($updated) {
            $this->updateCartTotals($cart);
        }
    }

    private function calculateFreeQty(float $qty): float
    {
        if ($qty < 10) {
            return 0.0;
        }

        return (float) floor($qty / 10);
    }
}
