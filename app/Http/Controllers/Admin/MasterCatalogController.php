<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Models\CompanyMaster;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $productsCount = $itemCategory->products()->count();
        if ($productsCount > 0) {
            return back()->with('error', "Cannot delete — {$productsCount} products use this category.");
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
        $productsCount = $companyMaster->products()->count();
        if ($productsCount > 0) {
            return back()->with('error', "Cannot delete — {$productsCount} products are linked.");
        }
        $companyMaster->delete();
        return back()->with('success', 'Company deleted.');
    }

    // ══════════════════════════════════════
    //  RACK LAYOUT
    // ══════════════════════════════════════

    public function rackLayoutIndex()
    {
        $this->adminOnly(request());

        return Inertia::render('Master/Racks/Index', [
            'sections' => RackSection::query()
                ->withCount(['allAreas as areas_count', 'products'])
                ->with(['allAreas' => fn ($query) => $query->withCount('products')->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function rackSectionStore(Request $request)
    {
        $this->adminOnly($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:rack_sections,name',
            'description' => 'nullable|string|max:1000',
            'status' => 'required|boolean',
        ]);

        RackSection::create($validated);

        return back()->with('success', 'Rack section added.');
    }

    public function rackSectionUpdate(Request $request, RackSection $rackSection)
    {
        $this->adminOnly($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('rack_sections', 'name')->ignore($rackSection->id)],
            'description' => 'nullable|string|max:1000',
            'status' => 'required|boolean',
        ]);

        $rackSection->update($validated);

        return back()->with('success', 'Rack section updated.');
    }

    public function rackSectionDestroy(RackSection $rackSection)
    {
        $this->adminOnly(request());

        $productsCount = Product::query()->where('rack_section_id', $rackSection->id)->count();
        if ($productsCount > 0) {
            return back()->with('error', "Cannot delete — {$productsCount} products still reference this rack section.");
        }

        $rackSection->delete();

        return back()->with('success', 'Rack section deleted.');
    }

    public function rackAreaStore(Request $request)
    {
        $this->adminOnly($request);

        $validated = $request->validate([
            'rack_section_id' => 'required|exists:rack_sections,id',
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rack_areas', 'name')->where(fn ($query) => $query->where('rack_section_id', $request->integer('rack_section_id'))),
            ],
            'status' => 'required|boolean',
        ]);

        RackArea::create($validated);

        return back()->with('success', 'Rack area added.');
    }

    public function rackAreaUpdate(Request $request, RackArea $rackArea)
    {
        $this->adminOnly($request);

        $validated = $request->validate([
            'rack_section_id' => 'required|exists:rack_sections,id',
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rack_areas', 'name')
                    ->where(fn ($query) => $query->where('rack_section_id', $request->integer('rack_section_id')))
                    ->ignore($rackArea->id),
            ],
            'status' => 'required|boolean',
        ]);

        $rackArea->update($validated);

        return back()->with('success', 'Rack area updated.');
    }

    public function rackAreaDestroy(RackArea $rackArea)
    {
        $this->adminOnly(request());

        $productsCount = Product::query()->where('rack_area_id', $rackArea->id)->count();
        if ($productsCount > 0) {
            return back()->with('error', "Cannot delete — {$productsCount} products still reference this rack area.");
        }

        $rackArea->delete();

        return back()->with('success', 'Rack area deleted.');
    }

    // ──────────────────────────────────────
    private function adminOnly(Request $request): void
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }
    }
}
