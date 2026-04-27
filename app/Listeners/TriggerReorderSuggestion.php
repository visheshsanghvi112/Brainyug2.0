<?php

namespace App\Listeners;

use App\Events\SaleCompleted;
use App\Models\B2bCart;
use App\Models\Franchisee;
use App\Models\InventoryLedger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TriggerReorderSuggestion
{
    public function handle(SaleCompleted $event): void
    {
        $franchiseeId = $event->franchiseeId;
        $salesInvoice = $event->salesInvoice;

        try {
            DB::transaction(function () use ($franchiseeId, $salesInvoice) {
                // Get products from this sale
                $saleProducts = $salesInvoice->items()
                    ->select('product_id')
                    ->distinct()
                    ->pluck('product_id')
                    ->toArray();

                if (empty($saleProducts)) {
                    return;
                }

                // Find products where current stock < reorder_quantity
                $productsNeedingReorder = Product::query()
                    ->whereIn('id', $saleProducts)
                    ->where('reorder_quantity', '>', 0)
                    ->lockForUpdate()
                    ->get(['id', 'reorder_quantity', 'sku']);

                foreach ($productsNeedingReorder as $product) {
                    // Get current stock at franchisee location
                    $currentStock = InventoryLedger::query()
                        ->where('product_id', $product->id)
                        ->where('location_type', 'franchisee')
                        ->where('location_id', $franchiseeId)
                        ->sum(DB::raw('qty_in - qty_out'));

                    $currentStock = (float) ($currentStock ?? 0);

                    // Check if below reorder threshold
                    if ($currentStock < $product->reorder_quantity) {
                        $this->createOrUpdateReorderSuggestion(
                            $franchiseeId,
                            $product->id,
                            $product->reorder_quantity,
                            $currentStock
                        );
                    }
                }
            });
        } catch (\Exception $e) {
            Log::error('Reorder suggestion generation failed', [
                'error' => $e->getMessage(),
                'franchisee_id' => $franchiseeId,
                'sales_invoice_id' => $salesInvoice->id,
            ]);
        }
    }

    private function createOrUpdateReorderSuggestion(
        int $franchiseeId,
        int $productId,
        int $reorderQty,
        float $currentStock
    ): void {
        // Check if there's already a draft cart for this franchisee
        $cart = B2bCart::query()
            ->where('franchisee_id', $franchiseeId)
            ->where('status', 'draft')
            ->first();

        if (!$cart) {
            // Get the franchisee's owner user for cart assignment
            $franchisee = \App\Models\Franchisee::findOrFail($franchiseeId);
            $ownerUser = $franchisee->users()->where('role', 'owner')->first() 
                ?? $franchisee->users()->first();
            
            if (!$ownerUser) {
                Log::warning('Cannot create reorder suggestion cart: franchisee has no users', [
                    'franchisee_id' => $franchiseeId,
                    'product_id' => $productId,
                ]);
                return;
            }

            // Create new draft cart
            $cart = B2bCart::create([
                'franchisee_id' => $franchiseeId,
                'user_id' => $ownerUser->id,
                'status' => 'draft',
                'notes' => 'Auto-generated on ' . now()->format('Y-m-d H:i:s') . ' — Stock reorder suggestions',
            ]);
        }

        // Check if product already in cart
        $cartItem = $cart->items()
            ->where('product_id', $productId)
            ->first();

        // Suggested order quantity = reorder_quantity - current_stock (rounded up)
        $suggestedQty = max(1, ceil($reorderQty - $currentStock));

        if ($cartItem) {
            // Update quantity if needed
            $cartItem->update(['qty' => max($cartItem->qty, $suggestedQty)]);
        } else {
            // Add new item to cart with suggested quantity
            $cart->items()->create([
                'product_id' => $productId,
                'qty' => $suggestedQty,
                'is_suggestion' => true,  // Mark as auto-suggested for UI hint
            ]);
        }

        // Mark cart as having unreviewed suggestions
        if ($cart->wasRecentlyCreated || !$cart->notes || !str_contains($cart->notes, 'unreviewed')) {
            $cart->update([
                'notes' => ($cart->notes ?? '') . "\n[UNREVIEWED SUGGESTIONS]",
            ]);
        }
    }
}
