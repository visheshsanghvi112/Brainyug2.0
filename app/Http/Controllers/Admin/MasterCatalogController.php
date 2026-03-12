<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Models\CompanyMaster;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterCatalogController extends Controller
{
    // ══════════════════════════════════════
    //  ITEM CATEGORIES
    // ══════════════════════════════════════

    public function categoriesIndex()
    {
        $this->adminOnly(request());

        return Inertia::render('Master/Categories/Index', [
            'categories' => ItemCategory::withCount('products')->latest()->paginate(15),
        ]);
    }

    public function categoriesStore(Request $request)
    {
        $this->adminOnly($request);
        $validated = $request->validate(['name' => 'required|string|max:100|unique:item_categories,name']);
        ItemCategory::create($validated);
        return back()->with('success', 'Category added.');
    }

    public function categoriesUpdate(Request $request, ItemCategory $itemCategory)
    {
        $this->adminOnly($request);
        $validated = $request->validate(['name' => "required|string|max:100|unique:item_categories,name,{$itemCategory->id}"]);
        $itemCategory->update($validated);
        return back()->with('success', 'Category updated.');
    }

    public function categoriesDestroy(ItemCategory $itemCategory)
    {
        $this->adminOnly(request());
        if ($itemCategory->products()->count() > 0) {
            return back()->with('error', "Cannot delete — {$itemCategory->products_count} products use this category.");
        }
        $itemCategory->delete();
        return back()->with('success', 'Category deleted.');
    }

    // ══════════════════════════════════════
    //  COMPANY MASTERS
    // ══════════════════════════════════════

    public function companiesIndex()
    {
        $this->adminOnly(request());

        return Inertia::render('Master/Companies/Index', [
            'companies' => CompanyMaster::withCount('products')->latest()->paginate(15),
        ]);
    }

    public function companiesStore(Request $request)
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'name'    => 'required|string|max:200',
            'address' => 'nullable|string|max:500',
            'gst_no'  => 'nullable|string|max:20',
            'dl_no'   => 'nullable|string|max:50',
            'preference' => 'nullable|string|max:255',
            'dump_days' => 'nullable|string|max:30',
            'expiry_receive_upto' => 'nullable|string|max:30',
            'minimum_margin' => 'nullable|string|max:30',
            'sales_tax' => 'nullable|string|max:30',
            'purchase_tax' => 'nullable|string|max:30',
        ]);
        CompanyMaster::create($validated);
        return back()->with('success', 'Company added.');
    }

    public function companiesUpdate(Request $request, CompanyMaster $companyMaster)
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'name'    => 'required|string|max:200',
            'address' => 'nullable|string|max:500',
            'gst_no'  => 'nullable|string|max:20',
            'dl_no'   => 'nullable|string|max:50',
            'preference' => 'nullable|string|max:255',
            'dump_days' => 'nullable|string|max:30',
            'expiry_receive_upto' => 'nullable|string|max:30',
            'minimum_margin' => 'nullable|string|max:30',
            'sales_tax' => 'nullable|string|max:30',
            'purchase_tax' => 'nullable|string|max:30',
        ]);
        $companyMaster->update($validated);
        return back()->with('success', 'Company updated.');
    }

    public function companiesDestroy(CompanyMaster $companyMaster)
    {
        $this->adminOnly(request());
        if ($companyMaster->products()->count() > 0) {
            return back()->with('error', "Cannot delete — {$companyMaster->products_count} products are linked.");
        }
        $companyMaster->delete();
        return back()->with('success', 'Company deleted.');
    }

    // ──────────────────────────────────────
    private function adminOnly(Request $request): void
    {
        if (!$request->user()->hasRole('Super Admin')) {
            abort(403);
        }
    }
}
