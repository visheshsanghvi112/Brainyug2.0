<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StockController extends Controller
{
    /**
     * Get stock for the current user's location via Cache (with brief TTL to prevent hammering)
     */
    public function current(Request $request)
    {
        $user = $request->user();
        
        // Admins default to warehouse, franchisees to their respective shop
        $locType = $user->hasRole('Franchisee') || $user->hasRole('Franchisee Staff') ? 'franchisee' : 'warehouse';
        $locId = $locType === 'franchisee' ? $user->getEffectiveFranchiseeId() : 1;

        if (!$locId) {
            return response()->json(['error' => 'No active location assigned.'], 403);
        }

        // Cache precision: Store current stock query for 60 seconds.
        // During rapid POS sales, individual batch IDs will invalidate or we fetch fresh for critical cart additions,
        // but for browsing the "Current Stock" screen, a 1-minute global cache is acceptable.
        $cacheKey = "api.stock.{$locType}.{$locId}";

        $stock = Cache::remember($cacheKey, 60, function() use ($locType, $locId) {
            return InventoryLedger::with(['product:id,product_name,sku,company_id,mrp', 'product.company:id,name'])
                ->where('location_type', $locType)
                ->where('location_id', $locId)
                ->select(
                    'product_id', 'batch_no', 'expiry_date', 'mrp',
                    DB::raw('SUM(qty_in - qty_out) as stock')
                )
                ->groupBy('product_id', 'batch_no', 'expiry_date', 'mrp')
                ->having('stock', '>', 0)
                ->get()
                ->map(function ($item) {
                     return [
                         'product_id' => $item->product_id,
                         'name' => $item->product->product_name ?? 'Unknown',
                         'sku' => $item->product->sku ?? null,
                         'company' => $item->product->company->name ?? 'N/A',
                         'batch_no' => $item->batch_no,
                         'expiry_date' => $item->expiry_date ? $item->expiry_date->format('Y-m-d') : null,
                         'is_expired' => $item->expiry_date && $item->expiry_date->isPast(),
                         'mrp' => (float)$item->mrp,
                         'stock_qty' => (float)$item->stock,
                     ];
                });
        });

        return response()->json([
            'location' => [
                'type' => $locType,
                'id' => $locId
            ],
            'cached' => true,
            'total_batches' => count($stock),
            'stock' => $stock
        ]);
    }
}
