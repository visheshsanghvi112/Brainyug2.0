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

        $cart = B2bCart::with(['items.product'])
            ->firstOrCreate([
                'franchisee_id' => $franchiseeId,
                'user_id' => $request->user()->id
            ]);

        return Inertia::render('B2b/Cart/Index', [
            'cart' => $cart,
            'products' => Product::where('is_active', true)->get(['id', 'product_name', 'sku', 'rate_a', 'hsn_id'])
        ]);
    }

    public function addToCart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|numeric|min:1',
        ]);

        $product = Product::find($validated['product_id']);
        $franchiseeId = $request->user()->getEffectiveFranchiseeId();

        $cart = B2bCart::firstOrCreate([
            'franchisee_id' => $franchiseeId,
            'user_id' => $request->user()->id
        ]);

        // Standard rate logic mapping to legacy `rate_a` for franchisees
        $rate = $product->rate_a;

        $cartItem = $cart->items()->where('product_id', $product->id)->first();

        // Very basic legacy free quantity rule: buy 10 get 1 free. Let's make it fixed configurable later.
        $freeQty = 0;
        if ($validated['qty'] >= 10) {
            $freeQty = floor($validated['qty'] / 10);
        }

        if ($cartItem) {
            $cartItem->increment('qty', $validated['qty']);
            $cartItem->update(['total_amount' => $cartItem->qty * $rate]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'qty' => $validated['qty'],
                'free_qty' => $freeQty, // Stored free qty here
                'rate' => $rate,
                'total_amount' => $validated['qty'] * $rate
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
        $cart = B2bCart::with('items.product')->where('user_id', $request->user()->id)->first();

        if (!$cart || $cart->items->isEmpty()) {
            return back()->with('error', 'Cart is empty. Add products to place an order.');
        }

        DB::transaction(function () use ($cart, $franchiseeId, $request) {
            $order = DistOrder::create([
                'order_number' => DistOrder::generateOrderNumber(),
                'franchisee_id' => $franchiseeId,
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'subtotal' => $cart->subtotal,
                'total_amount' => $cart->total_amount,
            ]);

            foreach ($cart->items as $item) {
                // Base setup, GST / Discount rules apply in detail when HO accepts
                $product = $item->product;
                $gst = $product->hsn ? $product->hsn->gst_rate : 0;
                
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'request_qty' => $item->qty,
                    'free_qty' => $item->free_qty ?? 0,
                    'rate' => $item->rate,
                    'mrp' => $product->mrp ?? 0,
                    'gst_percent' => $gst,
                    'taxable_amount' => $item->total_amount,
                    'gst_amount' => $item->total_amount * ($gst/100),
                    'total_amount' => $item->total_amount + ($item->total_amount * ($gst/100)),
                ]);
            }

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
}
