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
            $catalogVersion = Product::query()->max('updated_at')?->timestamp ?? 0;
            $cacheKey = "api.catalog.full.{$catalogVersion}";

            // Bulk sync scenario (Mobile caching logic)
            // Versioned cache ensures product/rate updates are visible immediately after master updates.
            $catalog = Cache::remember($cacheKey, 1800, function () {
                return Product::query()
                    ->visibleForFranchise()
                    ->with(['company:id,name', 'category:id,name', 'salt:id,name', 'hsn:id,hsn_code,cgst_percent,sgst_percent,igst_percent', 'boxSize:id,size_name'])
                    ->get()
                    ->map(fn (Product $product) => $this->serializeProduct($product));
            });

            return response()->json([
                'cached' => true,
                'count' => $catalog->count(),
                'data' => $catalog
            ]);
        }

        // Standard paginated fetch
        $search = $request->input('search');

        $query = Product::query()
            ->visibleForFranchise()
            ->with(['company:id,name', 'category:id,name', 'salt:id,name', 'hsn:id,hsn_code,cgst_percent,sgst_percent,igst_percent', 'boxSize:id,size_name']);

        if ($search) {
            $query->searchByTerm($search);
        }

        $products = $query->paginate($limit)
            ->through(fn (Product $product) => $this->serializeProduct($product));

        return response()->json($products);
    }

    /**
     * Get fully cached hierarchy of categories.
     */
    public function categories(Request $request)
    {
        $categories = Cache::rememberForever('api.catalog.categories', function () {
            return ItemCategory::query()
                ->whereNull('deleted_at')
                ->select('id', 'name')
                ->get();
        });

        return response()->json([
            'data' => $categories
        ]);
    }

    private function serializeProduct(Product $product): array
    {
        $franchiseRate = $product->franchiseRate();

        return [
            'id' => $product->id,
            'name' => $product->product_name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'product_code' => $product->product_code,
            'mrp' => (float) $product->mrp,
            'ptr' => (float) $product->ptr,
            'pts' => (float) $product->pts,
            'rate_a' => (float) $product->rate_a,
            'franchise_rate' => $franchiseRate,
            'conversion_factor' => $product->conversion_factor,
            'is_loose_sellable' => (bool) $product->is_loose_sellable,
            'company' => $product->company?->name,
            'category' => $product->category?->name,
            'salt' => $product->salt?->name,
            'box_size' => $product->boxSize?->size_name,
            'tax' => [
                'cgst' => (float) ($product->hsn?->cgst_percent ?? 0),
                'sgst' => (float) ($product->hsn?->sgst_percent ?? 0),
                'igst' => (float) ($product->hsn?->igst_percent ?? 0),
                'gst_percent' => $product->gstPercent(),
                'hsn_code' => $product->hsn?->hsn_code,
            ],
        ];
    }
}
