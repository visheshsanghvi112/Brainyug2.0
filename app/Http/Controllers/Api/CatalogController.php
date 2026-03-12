<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    /**
     * Get the full active product catalog, suitable for local SQLite syncing
     * on mobile devices. Cached for 1 hour to reduce DB hits.
     */
    public function index(Request $request)
    {
        // Support pagination for on-demand fetch OR full bulk fetch for sync
        $limit = $request->input('limit', 50);
        $page = $request->input('page', 1);
        $sync = $request->boolean('sync');

        if ($sync) {
            // Bulk sync scenario (Mobile caching logic)
            // Cache the ENTIRE active catalog structure for 1 hour
            $catalog = Cache::remember('api.catalog.full', 3600, function () {
                return Product::with(['company:id,name', 'category:id,name', 'salt:id,name', 'hsn:id,hsn_code,cgst_percent,sgst_percent', 'boxSize:id,name'])
                    ->where('is_active', true)
                    ->get()
                    // Map to a lighter payload to save mobile bandwidth
                    ->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'name' => $p->product_name,
                            'sku' => $p->sku,
                            'barcode' => $p->barcode,
                            'mrp' => (float)$p->mrp,
                            'ptr' => (float)$p->ptr, // For B2B pricing
                            'conversion_factor' => $p->conversion_factor,
                            'is_loose_sellable' => $p->is_loose_sellable,
                            'company' => $p->company?->name,
                            'category' => $p->category?->name,
                            'tax' => [
                                'cgst' => (float)($p->hsn?->cgst_percent ?? 0),
                                'sgst' => (float)($p->hsn?->sgst_percent ?? 0),
                            ]
                        ];
                    });
            });

            return response()->json([
                'cached' => true,
                'count' => $catalog->count(),
                'data' => $catalog
            ]);
        }

        // Standard paginated fetch
        $search = $request->input('search');

        $query = Product::with(['company', 'category', 'hsn'])
            ->where('is_active', true);

        if ($search) {
            $query->where('product_name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
        }

        $products = $query->paginate($limit);

        return response()->json($products);
    }

    /**
     * Get fully cached hierarchy of categories.
     */
    public function categories(Request $request)
    {
        $categories = Cache::rememberForever('api.catalog.categories', function () {
            return ItemCategory::where('is_active', true)
                ->select('id', 'name', 'slug')
                ->get();
        });

        return response()->json([
            'data' => $categories
        ]);
    }
}
