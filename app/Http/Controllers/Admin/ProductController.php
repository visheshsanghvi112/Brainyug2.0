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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

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

        DB::beginTransaction();
        try {
            $productData = $this->normalizeProductPayload(Arr::except($validated, $this->imageFieldNames()));

            if (blank($productData['product_code'] ?? null)) {
                $productData['product_code'] = $this->nextProductCode();
            }

            $product = Product::create($productData);
            $images = $this->storeProductImages($request, $product, []);
            if ($images !== []) {
                $product->update(['images' => $images]);
            }

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
            'product'       => $this->productFormPayload($product),
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

        DB::beginTransaction();
        try {
            $currentImages = (array) ($product->images ?? []);
            $productData = $this->normalizeProductPayload(Arr::except($validated, $this->imageFieldNames()));

            if (blank($productData['product_code'] ?? null)) {
                $productData['product_code'] = $product->product_code ?: $this->nextProductCode();
            }

            $product->update($productData);

            $images = $this->storeProductImages($request, $product, $currentImages);
            if ($images !== $currentImages) {
                $product->update(['images' => $images === [] ? null : $images]);
            }

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
     * Build a lean export query so bulk exports don't load unnecessary columns.
     */
    private function filteredProductExportQuery(Request $request)
    {
        return Product::query()
            ->select([
                'id',
                'product_name',
                'sku',
                'barcode',
                'product_code',
                'fast_search_index',
                'unit_sms_code',
                'packing_desc',
                'unit',
                'secondary_unit',
                'conversion_factor',
                'mrp',
                'ptr',
                'pts',
                'rate_a',
                'csr',
                'is_active',
                'company_id',
                'category_id',
                'salt_id',
                'hsn_id',
                'box_size_id',
            ])
            ->with([
                'company:id,name',
                'category:id,name',
                'salt:id,name',
                'hsn:id,hsn_code',
                'boxSize:id,size_name',
            ])
            ->when($request->search, function ($query, $search) {
                $words = array_filter(array_map('trim', preg_split('/\s+/', $search)));

                if (empty($words)) {
                    return;
                }

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
                                ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $like))
                                ->orWhereHas('salt', fn ($salt) => $salt->where('name', 'like', $like))
                                ->orWhereHas('hsn', fn ($hsn) => $hsn->where('hsn_code', 'like', $like));
                        });
                    }
                });
            })
            ->when($request->category, fn ($query, $value) => $query->where('category_id', $value))
            ->when($request->company, fn ($query, $value) => $query->where('company_id', $value))
            ->when($request->status !== null && $request->status !== '', function ($query) use ($request) {
                $query->where('is_active', (bool) $request->status);
            })
            ->orderBy('product_name');
    }

    /**
     * Export filtered product catalog as Excel (.xlsx).
     */
    public function exportExcel(Request $request)
    {
        $products = $this->filteredProductExportQuery($request)->get();

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
            $sheet->setCellValue([$colIdx + 1, 1], $header);
        }
        $sheet->getStyle('A1:Q1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data rows
        $row = 2;
        foreach ($products as $idx => $product) {
            $sheet->setCellValue([1,  $row], $idx + 1);
            $sheet->setCellValue([2,  $row], $product->product_name);
            $sheet->setCellValue([3,  $row], $product->salt?->name ?? '—');
            $sheet->setCellValue([4,  $row], $product->company?->name ?? '—');
            $sheet->setCellValue([5,  $row], $product->category?->name ?? '—');
            $sheet->setCellValue([6,  $row], $product->hsn?->hsn_code ?? '—');
            $sheet->setCellValue([7,  $row], $product->packing_desc ?? '—');
            $sheet->setCellValue([8,  $row], $product->boxSize?->size_name ?? '—');
            $sheet->setCellValue([9,  $row], $product->unit ?? '—');
            $sheet->setCellValue([10, $row], $product->secondary_unit ?? '—');
            $sheet->setCellValue([11, $row], $product->conversion_factor);
            $sheet->setCellValue([12, $row], (float) $product->mrp);
            $sheet->setCellValue([13, $row], (float) $product->ptr);
            $sheet->setCellValue([14, $row], (float) $product->pts);
            $sheet->setCellValue([15, $row], (float) ($product->rate_a ?? 0));
            $sheet->setCellValue([16, $row], (float) ($product->csr ?? 0));
            $sheet->setCellValue([17, $row], $product->is_active ? 'Active' : 'Inactive');

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
        $products = $this->filteredProductExportQuery($request)->get();

        return response()->view('exports.products-pdf', [
            'products' => $products,
            'generatedAt' => now()->format('d M Y, h:i A'),
            'totalCount' => $products->count(),
            'autoPrint' => true,
        ]);
    }

    /**
     * Reusable massive Validation constraints for the 40+ parameter enterprise product builder.
     */
    private function validateProduct(Request $request, $id = null)
    {
        $validator = Validator::make($request->all(), [
            // Core Identity
            'category_id' => 'required|exists:item_categories,id',
            'company_id' => 'required|exists:company_masters,id',
            'salt_id' => 'required|exists:salt_masters,id',
            'hsn_id' => 'required|exists:hsn_masters,id',
            'box_size_id' => 'nullable|exists:box_sizes,id',
            
            'product_name' => 'required|string|max:255',
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->ignore($id)->whereNull('deleted_at'),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->ignore($id)->whereNull('deleted_at'),
            ],
            'product_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'product_code')->ignore($id)->whereNull('deleted_at'),
            ],
            'unit_sms_code' => 'nullable|string|max:100',
            'item_type' => 'nullable|string|max:255',
            'color_item_type' => 'nullable|string|max:255',
            'company_code' => 'nullable|string|max:100',
            'product_type' => ['nullable', 'string', 'max:100', Rule::in(['Normal', 'Prohibited'])],
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
            'rack_section_id' => 'nullable|integer|exists:rack_sections,id',
            'rack_area_id' => 'nullable|integer|exists:rack_areas,id',

            // Display Flags
            'fast_search_index' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'hide' => 'boolean',
            'is_banned' => 'boolean',
            // Merchandising
            'image_front' => 'nullable|image|max:5120',
            'image_back' => 'nullable|image|max:5120',
            'image_left' => 'nullable|image|max:5120',
            'image_right' => 'nullable|image|max:5120',
        ]);

        $validator->after(function ($validator) use ($request) {
            $minStock = (int) $request->input('min_stock_level', 0);
            $maxStock = (int) $request->input('max_stock_level', 0);
            $mrp = (float) $request->input('mrp', 0);

            if ($maxStock > 0 && $minStock > $maxStock) {
                $validator->errors()->add('max_stock_level', 'Max stock level must be greater than or equal to min stock level.');
            }

            foreach (['ptr', 'pts', 'cost', 'rate_a', 'rate_b', 'rate_c'] as $field) {
                $value = $request->input($field);
                if ($value !== null && $value !== '' && (float) $value > $mrp) {
                    $validator->errors()->add($field, strtoupper(str_replace('_', ' ', $field)).' cannot exceed MRP.');
                }
            }

            $rackSectionId = $request->integer('rack_section_id');
            $rackAreaId = $request->integer('rack_area_id');

            if ($rackAreaId && !$rackSectionId) {
                $validator->errors()->add('rack_section_id', 'Rack section is required when a rack area is selected.');
            }

            if ($rackAreaId && $rackSectionId) {
                $belongsToSection = RackArea::query()
                    ->whereKey($rackAreaId)
                    ->where('rack_section_id', $rackSectionId)
                    ->exists();

                if (!$belongsToSection) {
                    $validator->errors()->add('rack_area_id', 'Selected rack area does not belong to the selected rack section.');
                }
            }
        });

        return $validator->validate();
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

        $hsn = HsnMaster::query()->findOrFail($request->input('hsn_id'), [
            'id',
            'hsn_code',
            'cgst_percent',
            'sgst_percent',
            'igst_percent',
        ]);

        return response()->json([
            'hsn_id' => $hsn->id,
            'hsn_code' => $hsn->hsn_code,
            'igst' => (float) $hsn->igst_percent,
            'sgst' => (float) $hsn->sgst_percent,
            'cgst' => (float) $hsn->cgst_percent,
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

        $products = Product::with('hsn:id,hsn_code,cgst_percent,sgst_percent,igst_percent')
            ->where('is_active', true)
            ->where('hide', false)
            ->where('is_banned', false)
            ->where(function ($q) use ($term) {
                $q->where('product_name', 'like', "%{$term}%")
                  ->orWhere('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%")
                  ->orWhere('product_code', 'like', "%{$term}%")
                  ->orWhere('fast_search_index', 'like', "%{$term}%");
            })
            ->select('id', 'product_name', 'sku', 'barcode', 'mrp', 'ptr', 'pts',
                     'rate_a', 'sgst', 'cgst', 'igst', 'hsn_id', 'packing_desc',
                     'conversion_factor', 'max_discount')
            ->limit(20)
            ->get();

        return response()->json($products);
    }

    private function normalizeProductPayload(array $payload): array
    {
        $stringFields = [
            'product_name',
            'sku',
            'barcode',
            'product_code',
            'unit_sms_code',
            'item_type',
            'color_item_type',
            'company_code',
            'product_type',
            'ap_remark',
            'free_schema',
            'unit',
            'secondary_unit',
            'packing_desc',
            'fast_search_index',
        ];

        foreach ($stringFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $payload[$field] = $this->normalizeNullableString($payload[$field]);
        }

        foreach (['sku', 'barcode', 'product_code', 'unit_sms_code', 'company_code'] as $field) {
            if (!blank($payload[$field] ?? null)) {
                $payload[$field] = Str::upper($payload[$field]);
            }
        }

        foreach (['product_name', 'packing_desc', 'item_type', 'color_item_type', 'free_schema', 'ap_remark'] as $field) {
            if (!blank($payload[$field] ?? null)) {
                $payload[$field] = preg_replace('/\s+/', ' ', trim((string) $payload[$field]));
            }
        }

        foreach ([
            'company_id', 'category_id', 'salt_id', 'hsn_id', 'box_size_id', 'conversion_factor', 'min_stock_level',
            'max_stock_level', 'reorder_quantity', 'shelflife', 'reorder_days', 'rack_section_id', 'rack_area_id',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->normalizeNullableInteger($payload[$field]);
            }
        }

        foreach ([
            'mrp', 'ptr', 'pts', 'cost', 'rate_a', 'rate_b', 'rate_c', 'p_rate_discount', 'item_special_discount',
            'special_discount', 'quantity_discount', 'max_discount', 'min_margin_disc', 'general_discount', 'local_tax',
            'central_tax', 'sgst', 'cgst', 'igst', 'csr',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->normalizeNullableDecimal($payload[$field]);
            }
        }

        foreach (['is_active', 'is_loose_sellable', 'hide', 'is_banned'] as $field) {
            $payload[$field] = (bool) ($payload[$field] ?? false);
        }

        if (blank($payload['fast_search_index'] ?? null)) {
            $payload['fast_search_index'] = $this->buildFastSearchIndex($payload);
        }

        return $payload;
    }

    private function nextProductCode(): string
    {
        $nextNumber = (int) Product::withTrashed()
            ->where('product_code', 'like', 'PRD-%')
            ->selectRaw("MAX(CAST(SUBSTRING(product_code, 5) AS UNSIGNED)) as max_code")
            ->value('max_code');

        do {
            $nextNumber++;
            $code = 'PRD-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        } while (Product::withTrashed()->where('product_code', $code)->exists());

        return $code;
    }

    private function buildFastSearchIndex(array $payload): string
    {
        $parts = array_filter([
            $payload['product_name'] ?? null,
            $payload['sku'] ?? null,
            $payload['barcode'] ?? null,
            $payload['product_code'] ?? null,
            $payload['unit_sms_code'] ?? null,
            $payload['company_code'] ?? null,
            $payload['packing_desc'] ?? null,
            $payload['item_type'] ?? null,
            $payload['color_item_type'] ?? null,
        ], fn ($value) => !blank($value));

        return Str::limit(implode(' | ', array_unique($parts)), 255, '');
    }

    private function storeProductImages(Request $request, Product $product, array $existingImages): array
    {
        $images = $existingImages;

        foreach ($this->imageFieldNames() as $field) {
            if (!$request->hasFile($field)) {
                continue;
            }

            $position = Str::after($field, 'image_');
            $newPath = $request->file($field)->store("products/{$product->id}", 'public');

            if (!empty($images[$position])) {
                Storage::disk('public')->delete($images[$position]);
            }

            $images[$position] = $newPath;
        }

        return array_filter($images);
    }

    private function imageFieldNames(): array
    {
        return ['image_front', 'image_back', 'image_left', 'image_right'];
    }

    private function productFormPayload(Product $product): array
    {
        $product->loadMissing(['rackArea:id,rack_section_id,name']);

        $images = collect((array) ($product->images ?? []))
            ->map(fn ($path, $position) => [
                'path' => $path,
                'url' => $this->resolveImageUrl($path),
                'position' => $position,
            ])
            ->all();

        return array_merge($product->toArray(), [
            'images' => $images,
        ]);
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeNullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}

