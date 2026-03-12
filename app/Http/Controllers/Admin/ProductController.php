<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\CompanyMaster;
use App\Models\ItemCategory;
use App\Models\SaltMaster;
use App\Models\HsnMaster;
use App\Models\BoxSize;
use App\Models\RackSection;
use App\Models\RackArea;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Barryvdh\DomPDF\Facade\Pdf;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = $this->filteredProductQuery($request)
            ->reorder()
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Master/Products/Index', [
            'products'   => ProductResource::collection($products),
            'filters'    => $request->only(['search', 'category', 'company', 'status']),
            'categories' => ItemCategory::orderBy('name')->get(['id', 'name']),
            'companies'  => CompanyMaster::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Master/Products/CreateEdit', [
            'companies'     => CompanyMaster::orderBy('name')->get(['id', 'name']),
            'categories'    => ItemCategory::orderBy('name')->get(['id', 'name']),
            'salts'         => SaltMaster::orderBy('name')->get(['id', 'name']),
            'hsn_codes'     => HsnMaster::orderBy('hsn_code')->get(['id', 'hsn_code']),
            'box_sizes'     => BoxSize::orderBy('size_name')->get(['id', 'size_name']),
            'rack_sections' => RackSection::where('status', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        $validated['product_name'] = trim($validated['product_name']);
        $validated['sku'] = Str::upper(trim($validated['sku']));
        if (isset($validated['product_code'])) $validated['product_code'] = Str::upper(trim($validated['product_code']));
        if (isset($validated['barcode'])) $validated['barcode'] = Str::upper(trim($validated['barcode']));

        DB::beginTransaction();
        try {
            Product::create($validated);
            DB::commit();
            Log::info("Master Catalog: New Enterprise Product SKU Initialized via HO Admin.");
            return redirect()->route('admin.products.index')->with('success', 'Enterprise Master Catalog initialized securely.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Enterprise Product Provisioning Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Catalog Integrity Failure: Unable to save Product Model.');
        }
    }

    public function edit(Product $product)
    {
        return Inertia::render('Master/Products/CreateEdit', [
            'product'       => $product,
            'companies'     => CompanyMaster::orderBy('name')->get(['id', 'name']),
            'categories'    => ItemCategory::orderBy('name')->get(['id', 'name']),
            'salts'         => SaltMaster::orderBy('name')->get(['id', 'name']),
            'hsn_codes'     => HsnMaster::orderBy('hsn_code')->get(['id', 'hsn_code']),
            'box_sizes'     => BoxSize::orderBy('size_name')->get(['id', 'size_name']),
            'rack_sections' => RackSection::where('status', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request, $product->id);

        $validated['product_name'] = trim($validated['product_name']);
        $validated['sku'] = Str::upper(trim($validated['sku']));
        if (isset($validated['product_code'])) $validated['product_code'] = Str::upper(trim($validated['product_code']));
        if (isset($validated['barcode'])) $validated['barcode'] = Str::upper(trim($validated['barcode']));

        DB::beginTransaction();
        try {
            $product->update($validated);
            DB::commit();
            return redirect()->route('admin.products.index')->with('success', 'Enterprise Master Catalog synchronized correctly.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Enterprise Product Update Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Catalog Integrity Failure: Unable to synchronize changes.');
        }
    }

    public function destroy(Product $product)
    {
        // Check if the product is linked in inventory or cart before archiving
        if ($product->inventoryLedgers()->exists() || $product->orderItems()->exists() || $product->b2bCartItems()->exists()) {
             return redirect()->back()->with('error', 'Referential Integrity Block: This Product SKU has active transactional history and cannot be deleted.');
        }

        DB::beginTransaction();
        try {
            $product->delete();
            DB::commit();
            return redirect()->route('admin.products.index')->with('success', 'Product Model securely archived.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product Archive Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Database integrity locked. Check systemic dependencies.');
        }
    }

    /**
     * Build a filtered product query (shared by index, exportExcel, exportPdf).
     */
    private function filteredProductQuery(Request $request)
    {
        return Product::with(['company', 'category', 'salt', 'hsn', 'boxSize'])
            ->when($request->search, function ($query, $search) {
                $words = array_filter(array_map('trim', preg_split('/\s+/', $search)));
                if (empty($words)) return;

                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $like = '%' . $word . '%';
                        $q->where(function ($inner) use ($like) {
                            $inner->where('product_name', 'like', $like)
                                  ->orWhere('sku', 'like', $like)
                                  ->orWhere('barcode', 'like', $like)
                                  ->orWhere('product_code', 'like', $like)
                                  ->orWhere('fast_search_index', 'like', $like)
                                  ->orWhere('unit_sms_code', 'like', $like)
                                  ->orWhere('packing_desc', 'like', $like)
                                  ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like))
                                  ->orWhereHas('salt', fn ($s) => $s->where('name', 'like', $like))
                                  ->orWhereHas('hsn', fn ($h) => $h->where('hsn_code', 'like', $like));
                        });
                    }
                });
            })
            ->when($request->category, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->company, fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->status !== null && $request->status !== '', function ($q) use ($request) {
                $q->where('is_active', (bool) $request->status);
            })
            ->orderBy('product_name');
    }

    /**
     * Export filtered product catalog as Excel (.xlsx).
     */
    public function exportExcel(Request $request)
    {
        $products = $this->filteredProductQuery($request)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Product Catalog');

        // Header columns matching legacy layout
        $headers = ['SR', 'PRODUCT NAME', 'CONTENT / SALT', 'COMPANY', 'CATEGORY', 'HSN CODE', 'PACKING', 'BOX SIZE', 'UNIT', 'SECONDARY', 'CONVERSION', 'MRP', 'PTR', 'PTS', 'RATE A (GST)', 'CSR', 'STATUS'];

        // Style the header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];

        foreach ($headers as $colIdx => $header) {
            $cell = $sheet->getCellByColumnAndRow($colIdx + 1, 1);
            $cell->setValue($header);
        }
        $sheet->getStyle('A1:Q1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data rows
        $row = 2;
        foreach ($products as $idx => $product) {
            $sheet->setCellValueByColumnAndRow(1, $row, $idx + 1);
            $sheet->setCellValueByColumnAndRow(2, $row, $product->product_name);
            $sheet->setCellValueByColumnAndRow(3, $row, $product->salt?->name ?? '—');
            $sheet->setCellValueByColumnAndRow(4, $row, $product->company?->name ?? '—');
            $sheet->setCellValueByColumnAndRow(5, $row, $product->category?->name ?? '—');
            $sheet->setCellValueByColumnAndRow(6, $row, $product->hsn?->hsn_code ?? '—');
            $sheet->setCellValueByColumnAndRow(7, $row, $product->packing_desc ?? '—');
            $sheet->setCellValueByColumnAndRow(8, $row, $product->boxSize?->size_name ?? '—');
            $sheet->setCellValueByColumnAndRow(9, $row, $product->unit ?? '—');
            $sheet->setCellValueByColumnAndRow(10, $row, $product->secondary_unit ?? '—');
            $sheet->setCellValueByColumnAndRow(11, $row, $product->conversion_factor);
            $sheet->setCellValueByColumnAndRow(12, $row, (float) $product->mrp);
            $sheet->setCellValueByColumnAndRow(13, $row, (float) $product->ptr);
            $sheet->setCellValueByColumnAndRow(14, $row, (float) $product->pts);
            $sheet->setCellValueByColumnAndRow(15, $row, (float) ($product->rate_a ?? 0));
            $sheet->setCellValueByColumnAndRow(16, $row, (float) ($product->csr ?? 0));
            $sheet->setCellValueByColumnAndRow(17, $row, $product->is_active ? 'Active' : 'Inactive');

            // Alternating row color
            if ($idx % 2 === 1) {
                $sheet->getStyle("A{$row}:Q{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F7FB');
            }
            $row++;
        }

        // Borders for data area
        $lastRow = $row - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("A2:Q{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDDDDD');
        }

        // Number format for price columns (L, M, N, O, P)
        if ($lastRow >= 2) {
            foreach (['L', 'M', 'N', 'O', 'P'] as $col) {
                $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }

        // Auto-size columns
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'ProductCatalog_' . now()->format('Y-m-d') . '.xlsx';
        $temp = tempnam(sys_get_temp_dir(), 'export');
        $writer = new Xlsx($spreadsheet);
        $writer->save($temp);

        return response()->download($temp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export filtered product catalog as PDF.
     */
    public function exportPdf(Request $request)
    {
        $products = $this->filteredProductQuery($request)->get();

        $pdf = Pdf::loadView('exports.products-pdf', [
            'products' => $products,
            'generatedAt' => now()->format('d M Y, h:i A'),
            'totalCount' => $products->count(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('ProductCatalog_' . now()->format('Y-m-d') . '.pdf');
    }

    /**
     * Reusable massive Validation constraints for the 40+ parameter enterprise product builder.
     */
    private function validateProduct(Request $request, $id = null)    {
        return $request->validate([
            // Core Identity
            'category_id' => 'required|exists:item_categories,id',
            'company_id' => 'required|exists:company_masters,id',
            'salt_id' => 'required|exists:salt_masters,id',
            'hsn_id' => 'required|exists:hsn_masters,id',
            'box_size_id' => 'nullable|exists:box_sizes,id',
            
            'product_name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku' . ($id ? ',' . $id : ''),
            'barcode' => 'nullable|string|max:255',
            'product_code' => 'nullable|string|max:50',
            'unit_sms_code' => 'nullable|string|max:100',
            'item_type' => 'nullable|string|max:255',
            'color_item_type' => 'nullable|string|max:255',
            'company_code' => 'nullable|string|max:100',
            'product_type' => 'nullable|string|max:100',
            'ap_remark' => 'nullable|string',

            // Pricing Tiers (Legacy + Modern)
            'mrp' => 'required|numeric|min:0',
            'ptr' => 'required|numeric|min:0',
            'pts' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'rate_a' => 'nullable|numeric|min:0',
            'rate_b' => 'nullable|numeric|min:0',
            'rate_c' => 'nullable|numeric|min:0',

            // Extreme Discount & Margin Parameters
            'p_rate_discount' => 'nullable|numeric|min:0|max:100',
            'item_special_discount' => 'nullable|numeric|min:0|max:100',
            'special_discount' => 'nullable|numeric|min:0|max:100',
            'quantity_discount' => 'nullable|numeric|min:0|max:100',
            'max_discount' => 'nullable|numeric|min:0|max:100',
            'min_margin_disc' => 'nullable|numeric|min:0|max:100',
            'general_discount' => 'nullable|numeric|min:0|max:100',
            'free_schema' => 'nullable|string|max:255', // e.g., '10+1'

            // Physical constraints
            'unit' => 'nullable|string|max:50',
            'secondary_unit' => 'nullable|string|max:50',
            'packing_desc' => 'nullable|string|max:100',
            'conversion_factor' => 'required|integer|min:1',
            'is_loose_sellable' => 'boolean',

            // Strict Inventory Triggers
            'min_stock_level' => 'nullable|integer|min:0',
            'max_stock_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'shelflife' => 'nullable|integer|min:0', // Months
            'reorder_days' => 'nullable|integer|min:0', // Days

            // Tax Computations
            'local_tax' => 'nullable|numeric|min:0|max:100',
            'central_tax' => 'nullable|numeric|min:0|max:100',
            'sgst' => 'nullable|numeric|min:0|max:100',
            'cgst' => 'nullable|numeric|min:0|max:100',
            'igst' => 'nullable|numeric|min:0|max:100',
            'csr' => 'nullable|numeric|min:0|max:100',

            // Warehouse Location Mapping
            'rack_section_id' => 'nullable|integer|min:1',
            'rack_area_id' => 'nullable|integer|min:1',

            // Display Flags
            'fast_search_index' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'hide' => 'boolean',
            'is_banned' => 'boolean',
        ]);
    }

    // ─────────────────────────────────────────────────────
    //  AJAX Helpers  (used by CreateEdit form & POS/Purchase)
    // ─────────────────────────────────────────────────────

    /**
     * Return GST rates (sgst, cgst, igst) for a given HSN code.
     * Allows the product form to auto-fill tax fields when HSN is selected.
     * Better than legacy: legacy made a separate PHP controller call; this is
     * a clean JSON endpoint that returns exactly what the frontend needs.
     */
    public function hsnTax(Request $request)
    {
        $request->validate(['hsn_id' => 'required|integer|exists:hsn_masters,id']);

        $hsn = HsnMaster::findOrFail($request->input('hsn_id'), ['id', 'hsn_code', 'gst_rate', 'description']);

        $halfGst = round($hsn->gst_rate / 2, 2);

        return response()->json([
            'hsn_id'   => $hsn->id,
            'hsn_code' => $hsn->hsn_code,
            'igst'     => $hsn->gst_rate,
            'sgst'     => $halfGst,
            'cgst'     => $halfGst,
        ]);
    }

    /**
     * Return rack areas belonging to a given section.
     * Allows the product form to cascade the rack_area dropdown when section changes.
     */
    public function rackAreas(Request $request)
    {
        $request->validate(['rack_section_id' => 'required|integer|exists:rack_sections,id']);

        $areas = RackArea::where('rack_section_id', $request->input('rack_section_id'))
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($areas);
    }

    /**
     * Check whether a product name is already taken (for real-time uniqueness feedback).
     * Better than legacy: legacy only caught duplicates on save → now instant feedback in UI.
     *
     * Returns: { exists: bool, id: int|null }
     */
    public function checkProductName(Request $request)
    {
        $request->validate(['name' => 'required|string|min:2']);

        $excludeId = (int) $request->input('exclude_id', 0);
        $name = trim($request->input('name'));

        $existing = Product::whereRaw('LOWER(product_name) = ?', [strtolower($name)])
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->first(['id', 'product_name', 'sku']);

        return response()->json([
            'exists' => (bool) $existing,
            'id'     => $existing?->id,
            'sku'    => $existing?->sku,
        ]);
    }

    /**
     * Quick product search for dropdowns in Purchase Invoice / POS.
     * Returns name + MRP + HSN + GST rates — everything needed to populate a line item row.
     */
    public function search(Request $request)
    {
        $term = trim($request->input('term', ''));
        if (strlen($term) < 2) {
            return response()->json([]);
        }

        $products = Product::with('hsn:id,hsn_code,gst_rate')
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('product_name', 'like', "%{$term}%")
                  ->orWhere('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%");
            })
            ->select('id', 'product_name', 'sku', 'barcode', 'mrp', 'ptr', 'pts',
                     'rate_a', 'sgst', 'cgst', 'igst', 'hsn_id', 'packing_desc',
                     'conversion_factor', 'max_discount')
            ->limit(20)
            ->get();

        return response()->json($products);
    }
}

