<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Controller;
use App\Models\B2bCart;
use App\Models\B2bCartItem;
use App\Models\Product;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Replaces the legacy Place_order / New_arrived_products_cart / Web Services cart logic
 * Gives Franchisees their specific portal tools.
 */
class CartController extends Controller
{
    public function index(Request $request)
    {
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();
        
        if (!$franchiseeId) {
            abort(403, 'You must be linked to a franchisee to access the cart.');
        }

        $cart = B2bCart::firstOrCreate([
            'franchisee_id' => $franchiseeId,
            'user_id' => $request->user()->id,
        ]);

        $this->syncCartRates($cart);

        $cart->load([
            'items.product' => fn ($query) => $query->with(['hsn:id,hsn_code'])
                ->select('id', 'product_name', 'sku', 'mrp', 'rate_a', 'ptr', 'pts', 'hsn_id'),
        ]);

        return Inertia::render('B2b/Cart/Index', [
            'cart' => $cart,
            'products' => Product::query()
                ->visibleForFranchise()
                ->with(['hsn:id,hsn_code'])
                ->orderBy('product_name')
                ->get(['id', 'product_name', 'sku', 'rate_a', 'ptr', 'pts', 'mrp', 'hsn_id'])
                ->map(function (Product $product) {
                    $product->rate_a = $product->franchiseRate();
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
            'qty' => 'required|numeric|min:1',
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
            'user_id' => $request->user()->id
        ]);

        // Standard rate logic mapping to legacy `rate_a` for franchisees
        $rate = $product->franchiseRate();

        $cartItem = $cart->items()->where('product_id', $product->id)->first();

        $requestedQty = (float) $validated['qty'];

        if ($cartItem) {
            $newQty = round((float) $cartItem->qty + $requestedQty, 2);
            $freeQty = $this->calculateFreeQty($newQty);

            $cartItem->update([
                'qty' => $newQty,
                'free_qty' => $freeQty,
                'rate' => $rate,
                'total_amount' => round($newQty * $rate, 2),
            ]);
        } else {
            $freeQty = $this->calculateFreeQty($requestedQty);

            $cart->items()->create([
                'product_id' => $product->id,
                'qty' => $requestedQty,
                'free_qty' => $freeQty, // Stored free qty here
                'rate' => $rate,
                'total_amount' => round($requestedQty * $rate, 2),
            ]);
        }
        
        $this->updateCartTotals($cart);

        return back()->with('success', 'Product added to cart.');
    }

    public function remove(Request $request, B2bCartItem $item)
    {
        if ($item->cart->user_id !== $request->user()->id) abort(403);
        
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

        $this->syncCartRates($cart);
        $cart->load('items.product.hsn');

        DB::transaction(function () use ($cart, $franchiseeId, $request) {
            $order = DistOrder::create([
                'order_number' => DistOrder::generateOrderNumber(),
                'franchisee_id' => $franchiseeId,
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'subtotal' => 0,
                'sgst_amount' => 0,
                'cgst_amount' => 0,
                'igst_amount' => 0,
                'total_amount' => 0,
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($cart->items as $item) {
                // Base setup, GST / Discount rules apply in detail when HO accepts
                $product = $item->product;
                if (!$product || !$product->is_active || $product->hide || $product->is_banned) {
                    abort(422, 'Cart contains product(s) no longer available for franchise ordering. Please refresh the cart.');
                }

                $currentRate = $product->franchiseRate();
                $gst = $product->gstPercent();
                $taxableAmount = round((float) $item->qty * $currentRate, 2);
                $gstAmount = round($taxableAmount * ($gst / 100), 2);
                $lineTotal = round($taxableAmount + $gstAmount, 2);

                $subtotal += $taxableAmount;
                $taxTotal += $gstAmount;
                
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'request_qty' => $item->qty,
                    'free_qty' => $item->free_qty ?? 0,
                    'rate' => $currentRate,
                    'mrp' => $product->mrp ?? 0,
                    'gst_percent' => $gst,
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

            // Flush cart
            $cart->items()->delete();
            $cart->update(['subtotal' => 0, 'total_amount' => 0]);
        });

        // Redirect to franchisee order history / success page (To be created)
        return redirect()->route('dashboard')->with('success', 'Order submitted successfully! HO will allocate batches and dispatch shortly.');
    }

    private function updateCartTotals(B2bCart $cart)
    {
        $subtotal = $cart->items()->sum('total_amount');
        $cart->update([
            'subtotal' => $subtotal,
            'total_amount' => $subtotal // Add GST calc here if needed inside cart directly
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

            $currentRate = $product->franchiseRate();
            $expectedTotal = round((float) $item->qty * $currentRate, 2);
            $expectedFreeQty = $this->calculateFreeQty((float) $item->qty);

            if (
                (float) $item->rate !== $currentRate
                || round((float) $item->total_amount, 2) !== $expectedTotal
                || round((float) $item->free_qty, 2) !== $expectedFreeQty
            ) {
                $item->update([
                    'rate' => $currentRate,
                    'total_amount' => $expectedTotal,
                    'free_qty' => $expectedFreeQty,
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
