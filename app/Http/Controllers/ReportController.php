<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\SalesInvoice;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Commission;
use App\Models\Franchisee;
use App\Models\Supplier;
use App\Models\User;
use App\Models\DistOrder;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportExportService $reportExportService
    ) {}

    /**
     * Get allowed franchisee IDs based on user hierarchy.
     * Returns null if user has unrestricted access.
     */
    private function getAllowedFranchiseeIds(User $user): ?array
    {
        if ($user->isAdmin() || $user->isAccount() || $user->isDistributer()) {
            return null; // Unrestricted
        }

        if ($user->isStateHead()) {
            return $user->managedFranchiseesSH()->pluck('id')->toArray();
        }

        if ($user->isRegionalHead()) {
            return Franchisee::query()
                ->whereIn('district_id', $user->assignedDistrictIds())
                ->pluck('id')
                ->toArray();
        }

        if ($user->isZonalHead()) {
            return $user->managedFranchiseesZH()->pluck('id')->toArray();
        }

        if ($user->isDistrictHead()) {
            return $user->managedFranchiseesDH()->pluck('id')->toArray();
        }

        if ($user->isFranchisee()) {
            return array_filter([$user->getEffectiveFranchiseeId()]);
        }

        return []; // Failsafe: no access
    }

    /**
     * Display stock summary (Overview).
     */
    public function stockSummary(Request $request)
    {
        $this->authorize('view reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $query = InventoryLedger::query()
            ->select(
                'location_type',
                'location_id',
                DB::raw('SUM(qty_in - qty_out) as current_stock'),
                DB::raw('COUNT(DISTINCT product_id) as unique_products')
            )
            ->groupBy('location_type', 'location_id');

        // Apply robust scoping
        if ($allowedIds !== null) {
            $query->where(function ($q) use ($allowedIds) {
                $q->where('location_type', 'franchisee')
                  ->whereIn('location_id', $allowedIds);
                // Non-admins cannot see warehouse stock here unless they are distributors, which return null above.
            });
        }

        $summary = $query->get()->map(function($record) {
            if ($record->location_type === 'warehouse') {
                $record->location_name = 'HO Warehouse';
            } else {
                $franchise = Franchisee::find($record->location_id);
                $record->location_name = $franchise ? $franchise->shop_name : 'Unknown Franchisee (ID: ' . $record->location_id . ')';
            }
            return $record;
        });

        // Sorting: Warehouse first, then Franchisees
        $summary = $summary->sortByDesc(fn($r) => $r->location_type === 'warehouse' ? 1 : 0)->values();

        return Inertia::render('Reports/Stock/Summary', [
            'summary' => $summary,
            'filters' => $request->only(['location_type', 'location_id']),
        ]);
    }

    /**
     * Detailed Batch-wise stock at a specific location.
     */
    public function stockCurrent(Request $request)
    {
        $this->authorize('view reports');
        $user = $request->user();
        $allowedIds = $this->getAllowedFranchiseeIds($user);

        // Default constraints for non-admins
        $defaultType = ($allowedIds !== null && count($allowedIds) > 0) ? 'franchisee' : 'warehouse';
        $defaultId = ($allowedIds !== null && count($allowedIds) > 0) ? $allowedIds[0] : 1;

        $locationType = $request->input('location_type', $defaultType);
        $locationId = (int)$request->input('location_id', $defaultId);

        // Security assertion: check if user is allowed to view this location
        if ($allowedIds !== null && $locationType === 'franchisee') {
            abort_unless(in_array($locationId, $allowedIds), 403, 'Unauthorized to view this territory.');
        } elseif ($allowedIds !== null && $locationType === 'warehouse') {
            abort_if($user->isFranchisee(), 403, 'Franchisees cannot view HO stock directly here.');
        }

        $query = InventoryLedger::with(['product.company', 'product.category'])
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->select(
                'product_id', 'batch_no', 'expiry_date', 'mrp',
                DB::raw('SUM(qty_in - qty_out) as stock')
            )
            ->groupBy('product_id', 'batch_no', 'expiry_date', 'mrp')
            ->having('stock', '>', 0);

        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($query) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Product ID', 'Product Name', 'Company', 'Category', 'Batch No', 'Expiry Date', 'MRP', 'Current Stock', 'Stock Value (MRP)']);
                
                // Stream using cursors to prevent memory exhaustion on 10,000+ rows
                foreach ($query->cursor() as $row) {
                    $stockVal = (float)$row->stock * (float)$row->mrp;
                    fputcsv($file, [
                        $row->product_id,
                        $row->product->product_name,
                        $row->product->company->name ?? '',
                        $row->product->category->name ?? '',
                        $row->batch_no,
                        $row->expiry_date ? $row->expiry_date->format('Y-m-d') : '',
                        $row->mrp,
                        $row->stock,
                        number_format($stockVal, 2, '.', '')
                    ]);
                }
                fclose($file);
            }, "stock_inventory_{$locationType}_{$locationId}_" . date('Ymd_His') . ".csv");
        }

        $stock = $query->paginate(20)->withQueryString();

        // Build list of allowed franchisees for a dropdown filter
        $franchiseesQuery = Franchisee::select('id', 'shop_name as name');
        if ($allowedIds !== null) {
            $franchiseesQuery->whereIn('id', $allowedIds);
        }

        return Inertia::render('Reports/Stock/Current', [
            'stock' => $stock,
            'location' => [
                'type' => $locationType,
                'id' => $locationId,
                'name' => $locationType === 'warehouse' ? 'HO Warehouse' : (Franchisee::find($locationId)->shop_name ?? 'Unknown')
            ],
            'franchisees' => $franchiseesQuery->get(),
            'filters' => $request->only(['location_type', 'location_id']),
        ]);
    }

    /**
     * Expiry report for upcoming 3/6/12 months.
     */
    public function stockExpiry(Request $request)
    {
        $this->authorize('view reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $months = (int)$request->input('months', 3);
        $threshold = Carbon::now()->addMonths($months);

        $query = InventoryLedger::with(['product'])
            ->select(
                'product_id', 'batch_no', 'expiry_date', 'location_type', 'location_id',
                DB::raw('SUM(qty_in - qty_out) as stock')
            )
            ->where('expiry_date', '<=', $threshold)
            ->groupBy('product_id', 'batch_no', 'expiry_date', 'location_type', 'location_id')
            ->having('stock', '>', 0)
            ->orderBy('expiry_date', 'asc');

        if ($allowedIds !== null) {
            $query->where(function($q) use ($allowedIds) {
                $q->where('location_type', 'franchisee')
                  ->whereIn('location_id', $allowedIds);
            });
        }

        return Inertia::render('Reports/Stock/Expiry', [
            'expired' => $query->get(),
            'months' => $months,
        ]);
    }

    /**
     * Non-moving stock report (Dead stock analysis).
     */
    public function stockNonMoving(Request $request)
    {
        $this->authorize('view reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $days = (int)$request->input('days', 90);
        $threshold = Carbon::now()->subDays($days);

        // Scope the "sold" logic. What constitutes a sale?
        // 'SALE' for franchisees, 'DISPATCH' for warehouse.
        $soldProductFilter = DB::table('inventory_ledgers')
            ->where('created_at', '>=', $threshold)
            ->whereIn('transaction_type', ['SALE', 'DISPATCH'])
            ->distinct()
            ->pluck('product_id')->toArray();

        // Products that have stock but ARE NOT in the sold list
        $query = InventoryLedger::with(['product.company'])
            ->select(
                'location_type', 'location_id', 'product_id',
                DB::raw('SUM(qty_in - qty_out) as current_stock')
            )
            ->whereNotIn('product_id', $soldProductFilter)
            ->groupBy('location_type', 'location_id', 'product_id')
            ->having('current_stock', '>', 0);

        if ($allowedIds !== null) {
            $query->where(function($q) use ($allowedIds) {
                $q->where('location_type', 'franchisee')
                  ->whereIn('location_id', $allowedIds);
            });
        }

        return Inertia::render('Reports/Stock/NonMoving', [
            'stock' => $query->paginate(20)->withQueryString(),
            'days' => $days,
        ]);
    }

    /**
     * GSTR-1 (Sales/Outward Supplies)
     */
    public function gstr1(Request $request)
    {
        $this->authorize('view gst reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());

        // Retail outward (B2C) from POS invoices.
        $retailQuery = DB::table('sales_invoice_items as sit')
            ->join('sales_invoices as si', 'sit.sales_invoice_id', '=', 'si.id')
            ->join('products as p', 'sit.product_id', '=', 'p.id')
            ->leftJoin('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('si.date_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('si.status', 'completed')
            ->select(
                DB::raw("COALESCE(h.hsn_code, 'UNMAPPED') as hsn_code"),
                DB::raw('SUM(sit.taxable_amount) as taxable_value'),
                DB::raw('sit.gst_percent as rate'),
                DB::raw('SUM(sit.gst_amount) as total_gst'),
                DB::raw("SUM(COALESCE(sit.gst_amount * (COALESCE(h.cgst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)) as cgst_amount"),
                DB::raw("SUM(COALESCE(sit.gst_amount * (COALESCE(h.sgst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)) as sgst_amount"),
                DB::raw("SUM(COALESCE(sit.gst_amount * (COALESCE(h.igst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)) as igst_amount"),
                DB::raw("'B2C_RETAIL' as supply_type")
            );

        if ($allowedIds !== null) {
            $retailQuery->whereIn('si.franchisee_id', $allowedIds);
        }

        $results = $retailQuery
            ->groupBy('h.hsn_code', 'sit.gst_percent')
            ->get();

        // HO dispatch outward (B2B) belongs to central filing scope only.
        if ($allowedIds === null) {
            $taxableExpr = 'COALESCE(NULLIF(doi.taxable_amount, 0), (COALESCE(doi.approved_qty, doi.request_qty, 0) * doi.rate))';
            $gstExpr = 'COALESCE(NULLIF(doi.gst_amount, 0), (' . $taxableExpr . ' * COALESCE(doi.gst_percent, 0) / 100))';
            $headerTaxBase = 'NULLIF((COALESCE(do.cgst_amount,0) + COALESCE(do.sgst_amount,0) + COALESCE(do.igst_amount,0)), 0)';

            $dispatchRows = DB::table('dist_order_items as doi')
                ->join('dist_orders as do', 'doi.dist_order_id', '=', 'do.id')
                ->leftJoin('products as p', 'doi.product_id', '=', 'p.id')
                ->leftJoin('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
                ->whereBetween('do.dispatched_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereIn('do.status', ['dispatched', 'delivered'])
                ->select(
                    DB::raw("COALESCE(h.hsn_code, 'UNMAPPED') as hsn_code"),
                    DB::raw('COALESCE(doi.gst_percent, 0) as rate'),
                    DB::raw('SUM(' . $taxableExpr . ') as taxable_value'),
                    DB::raw('SUM(' . $gstExpr . ') as total_gst'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.cgst_amount,0) / ' . $headerTaxBase . '), 0)) as cgst_amount'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.sgst_amount,0) / ' . $headerTaxBase . '), 0)) as sgst_amount'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.igst_amount,0) / ' . $headerTaxBase . '), 0)) as igst_amount'),
                    DB::raw("'B2B_HO_DISPATCH' as supply_type")
                )
                ->groupBy('h.hsn_code', 'doi.gst_percent')
                ->get();

            $results = $results->concat($dispatchRows)->values();
        }

        $summary = [
            'taxable_value' => round((float) $results->sum('taxable_value'), 2),
            'total_gst' => round((float) $results->sum('total_gst'), 2),
            'cgst_amount' => round((float) $results->sum('cgst_amount'), 2),
            'sgst_amount' => round((float) $results->sum('sgst_amount'), 2),
            'igst_amount' => round((float) $results->sum('igst_amount'), 2),
        ];

        if ($format = $this->requestedExportFormat($request)) {
            $headers = ['HSN Code', 'Supply Type', 'Tax Rate (%)', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST'];
            $rowsForExport = $results->map(fn ($row) => [
                $row->hsn_code,
                $row->supply_type,
                (float) $row->rate,
                round((float) $row->taxable_value, 2),
                round((float) $row->cgst_amount, 2),
                round((float) $row->sgst_amount, 2),
                round((float) $row->igst_amount, 2),
                round((float) $row->total_gst, 2),
            ])->all();

            $meta = [
                'From Date' => $startDate,
                'To Date' => $endDate,
                'Taxable Value' => $summary['taxable_value'],
                'CGST' => $summary['cgst_amount'],
                'SGST' => $summary['sgst_amount'],
                'IGST' => $summary['igst_amount'],
                'Total GST' => $summary['total_gst'],
            ];

            if ($format === 'excel') {
                return $this->reportExportService->downloadExcel(
                    fileBase: 'gstr1_outward',
                    sheetTitle: 'GSTR-1 Outward',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            if ($format === 'pdf') {
                return $this->reportExportService->downloadPdf(
                    fileBase: 'gstr1_outward',
                    title: 'GSTR-1 Outward Supplies',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            return response()->streamDownload(function () use ($results, $summary) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['HSN Code', 'Supply Type', 'Tax Rate (%)', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST']);
                foreach ($results as $row) {
                    fputcsv($file, [
                        $row->hsn_code,
                        $row->supply_type,
                        $row->rate,
                        $row->taxable_value,
                        $row->cgst_amount,
                        $row->sgst_amount,
                        $row->igst_amount,
                        $row->total_gst
                    ]);
                }
                fputcsv($file, []);
                fputcsv($file, ['TOTAL', '', '', $summary['taxable_value'], $summary['cgst_amount'], $summary['sgst_amount'], $summary['igst_amount'], $summary['total_gst']]);
                fclose($file);
            }, "gstr1_outward_" . date('Ymd_His') . ".csv");
        }

        return Inertia::render('Reports/GST/Gstr1', [
            'rows' => $results,
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'complianceNotes' => [
                'GSTR-1 is outward-supplies detail return; GSTR-3B is summary return. Ensure consistency between both.',
                'This report segregates B2C retail and HO B2B dispatch outward supplies by HSN and GST rate.',
                'Review unclassified HSN rows before filing to avoid return validation issues.',
            ],
        ]);
    }

    /**
     * GSTR-2 (Purchases/Inward Supplies)
     */
    public function gstr2(Request $request)
    {
        $this->authorize('view gst reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());

        // HO inward from supplier purchase invoices.
        $hoPurchasesQuery = DB::table('purchase_invoice_items as pit')
            ->join('purchase_invoices as pi', 'pit.purchase_invoice_id', '=', 'pi.id')
            ->leftJoin('products as p', 'pit.product_id', '=', 'p.id')
            ->leftJoin('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('pi.invoice_date', [$startDate, $endDate])
            ->where('pi.status', 'approved')
            ->select(
                DB::raw("COALESCE(h.hsn_code, 'UNMAPPED') as hsn_code"),
                DB::raw('SUM(pit.taxable_amount) as taxable_value'),
                DB::raw('pit.gst_percent as rate'),
                DB::raw('SUM(pit.gst_amount) as total_gst'),
                DB::raw("SUM(CASE WHEN pi.tax_type = 'intra_state' THEN pit.gst_amount / 2 ELSE 0 END) as cgst_amount"),
                DB::raw("SUM(CASE WHEN pi.tax_type = 'intra_state' THEN pit.gst_amount / 2 ELSE 0 END) as sgst_amount"),
                DB::raw("SUM(CASE WHEN pi.tax_type = 'inter_state' THEN pit.gst_amount ELSE 0 END) as igst_amount"),
                DB::raw("'HO_PURCHASE' as source_type")
            );

        $rows = collect();

        if ($allowedIds === null) {
            $rows = $hoPurchasesQuery
                ->groupBy('h.hsn_code', 'pit.gst_percent', 'pi.tax_type')
                ->get();
        } else {
            // Franchise/territory inward from HO dispatches.
            $taxableExpr = 'COALESCE(NULLIF(doi.taxable_amount, 0), (COALESCE(doi.approved_qty, doi.request_qty, 0) * doi.rate))';
            $gstExpr = 'COALESCE(NULLIF(doi.gst_amount, 0), (' . $taxableExpr . ' * COALESCE(doi.gst_percent, 0) / 100))';
            $headerTaxBase = 'NULLIF((COALESCE(do.cgst_amount,0) + COALESCE(do.sgst_amount,0) + COALESCE(do.igst_amount,0)), 0)';

            $rows = DB::table('dist_order_items as doi')
                ->join('dist_orders as do', 'doi.dist_order_id', '=', 'do.id')
                ->leftJoin('products as p', 'doi.product_id', '=', 'p.id')
                ->leftJoin('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
                ->whereBetween('do.dispatched_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereIn('do.status', ['dispatched', 'delivered'])
                ->whereIn('do.franchisee_id', $allowedIds)
                ->select(
                    DB::raw("COALESCE(h.hsn_code, 'UNMAPPED') as hsn_code"),
                    DB::raw('COALESCE(doi.gst_percent, 0) as rate'),
                    DB::raw('SUM(' . $taxableExpr . ') as taxable_value'),
                    DB::raw('SUM(' . $gstExpr . ') as total_gst'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.cgst_amount,0) / ' . $headerTaxBase . '), 0)) as cgst_amount'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.sgst_amount,0) / ' . $headerTaxBase . '), 0)) as sgst_amount'),
                    DB::raw('SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.igst_amount,0) / ' . $headerTaxBase . '), 0)) as igst_amount'),
                    DB::raw("'HO_DISPATCH_RECEIPT' as source_type")
                )
                ->groupBy('h.hsn_code', 'doi.gst_percent')
                ->get();
        }

        $summary = [
            'taxable_value' => round((float) $rows->sum('taxable_value'), 2),
            'total_gst' => round((float) $rows->sum('total_gst'), 2),
            'cgst_amount' => round((float) $rows->sum('cgst_amount'), 2),
            'sgst_amount' => round((float) $rows->sum('sgst_amount'), 2),
            'igst_amount' => round((float) $rows->sum('igst_amount'), 2),
        ];

        if ($format = $this->requestedExportFormat($request)) {
            $headers = ['HSN Code', 'Source', 'Tax Rate (%)', 'Taxable Value', 'CGST ITC', 'SGST ITC', 'IGST ITC', 'Total ITC'];
            $rowsForExport = $rows->map(fn ($row) => [
                $row->hsn_code,
                $row->source_type,
                (float) $row->rate,
                round((float) $row->taxable_value, 2),
                round((float) $row->cgst_amount, 2),
                round((float) $row->sgst_amount, 2),
                round((float) $row->igst_amount, 2),
                round((float) $row->total_gst, 2),
            ])->all();

            $meta = [
                'From Date' => $startDate,
                'To Date' => $endDate,
                'Taxable Inward' => $summary['taxable_value'],
                'CGST ITC' => $summary['cgst_amount'],
                'SGST ITC' => $summary['sgst_amount'],
                'IGST ITC' => $summary['igst_amount'],
                'Total ITC' => $summary['total_gst'],
            ];

            if ($format === 'excel') {
                return $this->reportExportService->downloadExcel(
                    fileBase: 'gstr2_inward',
                    sheetTitle: 'GSTR-2 ITC',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            if ($format === 'pdf') {
                return $this->reportExportService->downloadPdf(
                    fileBase: 'gstr2_inward',
                    title: 'GSTR-2 Inward / ITC Summary',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            return response()->streamDownload(function () use ($rows, $summary) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['HSN Code', 'Source', 'Tax Rate (%)', 'Taxable Value', 'CGST ITC', 'SGST ITC', 'IGST ITC', 'Total ITC']);
                foreach ($rows as $row) {
                    fputcsv($file, [
                        $row->hsn_code,
                        $row->source_type,
                        $row->rate,
                        $row->taxable_value,
                        $row->cgst_amount,
                        $row->sgst_amount,
                        $row->igst_amount,
                        $row->total_gst
                    ]);
                }
                fputcsv($file, []);
                fputcsv($file, ['TOTAL', '', '', $summary['taxable_value'], $summary['cgst_amount'], $summary['sgst_amount'], $summary['igst_amount'], $summary['total_gst']]);
                fclose($file);
            }, "gstr2_inward_" . date('Ymd_His') . ".csv");
        }

        return Inertia::render('Reports/GST/Gstr2', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'complianceNotes' => [
                'GSTR-2B reconciliation should be completed before final ITC claim in GSTR-3B.',
                'ITC is split as CGST/SGST/IGST for easier return mapping and liability set-off checks.',
                'Review unclassified HSN rows and missing tax types before filing period closure.',
            ],
        ]);
    }

    /**
     * GSTR-3B summary-style view: outward liability vs inward ITC.
     */
    public function gstr3b(Request $request)
    {
        $this->authorize('view gst reports');

        $allowedIds = $this->getAllowedFranchiseeIds($request->user());
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());

        // Outward summary (Table 3.1 approximation from internal books)
        $retailOutward = DB::table('sales_invoice_items as sit')
            ->join('sales_invoices as si', 'sit.sales_invoice_id', '=', 'si.id')
            ->leftJoin('products as p', 'sit.product_id', '=', 'p.id')
            ->leftJoin('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('si.date_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('si.status', 'completed')
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('si.franchisee_id', $allowedIds))
            ->selectRaw('COALESCE(SUM(sit.taxable_amount),0) as taxable_value')
            ->selectRaw('COALESCE(SUM(sit.gst_amount),0) as total_gst')
            ->selectRaw("COALESCE(SUM(COALESCE(sit.gst_amount * (COALESCE(h.cgst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)),0) as cgst_amount")
            ->selectRaw("COALESCE(SUM(COALESCE(sit.gst_amount * (COALESCE(h.sgst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)),0) as sgst_amount")
            ->selectRaw("COALESCE(SUM(COALESCE(sit.gst_amount * (COALESCE(h.igst_percent, 0) / NULLIF((COALESCE(h.cgst_percent,0) + COALESCE(h.sgst_percent,0) + COALESCE(h.igst_percent,0)), 0)), 0)),0) as igst_amount")
            ->first();

        $dispatchOutward = (object) [
            'taxable_value' => 0,
            'total_gst' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
        ];

        if ($allowedIds === null) {
            $taxableExpr = 'COALESCE(NULLIF(doi.taxable_amount, 0), (COALESCE(doi.approved_qty, doi.request_qty, 0) * doi.rate))';
            $gstExpr = 'COALESCE(NULLIF(doi.gst_amount, 0), (' . $taxableExpr . ' * COALESCE(doi.gst_percent, 0) / 100))';
            $headerTaxBase = 'NULLIF((COALESCE(do.cgst_amount,0) + COALESCE(do.sgst_amount,0) + COALESCE(do.igst_amount,0)), 0)';

            $dispatchOutward = DB::table('dist_order_items as doi')
                ->join('dist_orders as do', 'doi.dist_order_id', '=', 'do.id')
                ->whereBetween('do.dispatched_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereIn('do.status', ['dispatched', 'delivered'])
                ->selectRaw('COALESCE(SUM(' . $taxableExpr . '),0) as taxable_value')
                ->selectRaw('COALESCE(SUM(' . $gstExpr . '),0) as total_gst')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.cgst_amount,0) / ' . $headerTaxBase . '), 0)),0) as cgst_amount')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.sgst_amount,0) / ' . $headerTaxBase . '), 0)),0) as sgst_amount')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.igst_amount,0) / ' . $headerTaxBase . '), 0)),0) as igst_amount')
                ->first();
        }

        $outward = [
            'taxable_value' => round((float) $retailOutward->taxable_value + (float) $dispatchOutward->taxable_value, 2),
            'cgst_amount' => round((float) $retailOutward->cgst_amount + (float) $dispatchOutward->cgst_amount, 2),
            'sgst_amount' => round((float) $retailOutward->sgst_amount + (float) $dispatchOutward->sgst_amount, 2),
            'igst_amount' => round((float) $retailOutward->igst_amount + (float) $dispatchOutward->igst_amount, 2),
            'total_gst' => round((float) $retailOutward->total_gst + (float) $dispatchOutward->total_gst, 2),
        ];

        // ITC summary (Table 4 approximation from internal books)
        $itc = [
            'taxable_value' => 0.0,
            'cgst_amount' => 0.0,
            'sgst_amount' => 0.0,
            'igst_amount' => 0.0,
            'total_gst' => 0.0,
        ];

        if ($allowedIds === null) {
            $purchase = DB::table('purchase_invoice_items as pit')
                ->join('purchase_invoices as pi', 'pit.purchase_invoice_id', '=', 'pi.id')
                ->whereBetween('pi.invoice_date', [$startDate, $endDate])
                ->where('pi.status', 'approved')
                ->selectRaw('COALESCE(SUM(pit.taxable_amount),0) as taxable_value')
                ->selectRaw("COALESCE(SUM(CASE WHEN pi.tax_type = 'intra_state' THEN pit.gst_amount / 2 ELSE 0 END),0) as cgst_amount")
                ->selectRaw("COALESCE(SUM(CASE WHEN pi.tax_type = 'intra_state' THEN pit.gst_amount / 2 ELSE 0 END),0) as sgst_amount")
                ->selectRaw("COALESCE(SUM(CASE WHEN pi.tax_type = 'inter_state' THEN pit.gst_amount ELSE 0 END),0) as igst_amount")
                ->selectRaw('COALESCE(SUM(pit.gst_amount),0) as total_gst')
                ->first();

            $itc = [
                'taxable_value' => round((float) $purchase->taxable_value, 2),
                'cgst_amount' => round((float) $purchase->cgst_amount, 2),
                'sgst_amount' => round((float) $purchase->sgst_amount, 2),
                'igst_amount' => round((float) $purchase->igst_amount, 2),
                'total_gst' => round((float) $purchase->total_gst, 2),
            ];
        } else {
            $taxableExpr = 'COALESCE(NULLIF(doi.taxable_amount, 0), (COALESCE(doi.approved_qty, doi.request_qty, 0) * doi.rate))';
            $gstExpr = 'COALESCE(NULLIF(doi.gst_amount, 0), (' . $taxableExpr . ' * COALESCE(doi.gst_percent, 0) / 100))';
            $headerTaxBase = 'NULLIF((COALESCE(do.cgst_amount,0) + COALESCE(do.sgst_amount,0) + COALESCE(do.igst_amount,0)), 0)';

            $dispatchInward = DB::table('dist_order_items as doi')
                ->join('dist_orders as do', 'doi.dist_order_id', '=', 'do.id')
                ->whereBetween('do.dispatched_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereIn('do.status', ['dispatched', 'delivered'])
                ->whereIn('do.franchisee_id', $allowedIds)
                ->selectRaw('COALESCE(SUM(' . $taxableExpr . '),0) as taxable_value')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.cgst_amount,0) / ' . $headerTaxBase . '), 0)),0) as cgst_amount')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.sgst_amount,0) / ' . $headerTaxBase . '), 0)),0) as sgst_amount')
                ->selectRaw('COALESCE(SUM(COALESCE((' . $gstExpr . ') * (COALESCE(do.igst_amount,0) / ' . $headerTaxBase . '), 0)),0) as igst_amount')
                ->selectRaw('COALESCE(SUM(' . $gstExpr . '),0) as total_gst')
                ->first();

            $itc = [
                'taxable_value' => round((float) $dispatchInward->taxable_value, 2),
                'cgst_amount' => round((float) $dispatchInward->cgst_amount, 2),
                'sgst_amount' => round((float) $dispatchInward->sgst_amount, 2),
                'igst_amount' => round((float) $dispatchInward->igst_amount, 2),
                'total_gst' => round((float) $dispatchInward->total_gst, 2),
            ];
        }

        $netPayable = max(0, round($outward['total_gst'] - $itc['total_gst'], 2));
        $itcCarryForward = max(0, round($itc['total_gst'] - $outward['total_gst'], 2));

        $summary = [
            'outward' => $outward,
            'itc' => $itc,
            'net_tax_payable' => $netPayable,
            'itc_carry_forward' => $itcCarryForward,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];

        if ($format = $this->requestedExportFormat($request)) {
            $headers = ['Section', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST'];
            $rowsForExport = [
                ['Outward Supplies', $summary['outward']['taxable_value'], $summary['outward']['cgst_amount'], $summary['outward']['sgst_amount'], $summary['outward']['igst_amount'], $summary['outward']['total_gst']],
                ['Eligible ITC', $summary['itc']['taxable_value'], $summary['itc']['cgst_amount'], $summary['itc']['sgst_amount'], $summary['itc']['igst_amount'], $summary['itc']['total_gst']],
                ['Net Tax Payable', '', '', '', '', $summary['net_tax_payable']],
                ['ITC Carry Forward', '', '', '', '', $summary['itc_carry_forward']],
            ];

            $meta = [
                'From Date' => $startDate,
                'To Date' => $endDate,
                'Outward GST' => $summary['outward']['total_gst'],
                'Eligible ITC' => $summary['itc']['total_gst'],
            ];

            if ($format === 'excel') {
                return $this->reportExportService->downloadExcel(
                    fileBase: 'gstr3b_summary',
                    sheetTitle: 'GSTR-3B',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            if ($format === 'pdf') {
                return $this->reportExportService->downloadPdf(
                    fileBase: 'gstr3b_summary',
                    title: 'GSTR-3B Liability vs ITC Summary',
                    headers: $headers,
                    rows: $rowsForExport,
                    meta: $meta,
                );
            }

            return response()->streamDownload(function () use ($summary) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Section', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST']);
                fputcsv($file, ['Outward Supplies', $summary['outward']['taxable_value'], $summary['outward']['cgst_amount'], $summary['outward']['sgst_amount'], $summary['outward']['igst_amount'], $summary['outward']['total_gst']]);
                fputcsv($file, ['Eligible ITC', $summary['itc']['taxable_value'], $summary['itc']['cgst_amount'], $summary['itc']['sgst_amount'], $summary['itc']['igst_amount'], $summary['itc']['total_gst']]);
                fputcsv($file, []);
                fputcsv($file, ['Net Tax Payable', '', '', '', '', $summary['net_tax_payable']]);
                fputcsv($file, ['ITC Carry Forward', '', '', '', '', $summary['itc_carry_forward']]);
                fclose($file);
            }, 'gstr3b_summary_' . date('Ymd_His') . '.csv');
        }

        return Inertia::render('Reports/GST/Gstr3b', [
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'complianceNotes' => [
                'GSTR-3B is a summary return; reconcile with GSTR-1 and purchase ITC records before filing.',
                'From GST portal process updates, ensure outward values are prepared correctly in GSTR-1 before final 3B filing.',
                'Run GSTR-2B reconciliation externally and claim only eligible ITC in final filing workflow.',
            ],
        ]);
    }

    /**
     * Supports `export=true` (legacy CSV), `export_format=csv|excel|pdf`, and `format=...`.
     */
    private function requestedExportFormat(Request $request): ?string
    {
        $format = strtolower((string) ($request->input('export_format') ?: $request->input('format') ?: ''));

        if (in_array($format, ['csv', 'excel', 'pdf'], true)) {
            return $format;
        }

        if ($request->boolean('export')) {
            return 'csv';
        }

        return null;
    }

    /**
     * Daily sales register with role-aware scoping and export.
     */
    public function dailySalesRegister(Request $request)
    {
        $this->authorize('view reports');

        $allowedIds = $this->getAllowedFranchiseeIds($request->user());
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());

        $query = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('franchisees as f', 'f.id', '=', 'si.franchisee_id')
            ->whereBetween('si.date_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('si.franchisee_id', $allowedIds))
            ->when($request->filled('status'), fn ($q) => $q->where('si.status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->input('search'));
                $q->where(function ($inner) use ($search) {
                    $inner->where('si.bill_no', 'like', "%{$search}%")
                        ->orWhere('c.name', 'like', "%{$search}%")
                        ->orWhere('c.mobile', 'like', "%{$search}%")
                        ->orWhere('f.shop_name', 'like', "%{$search}%")
                        ->orWhere('f.shop_code', 'like', "%{$search}%");
                });
            });

        $rowsQuery = (clone $query)
            ->leftJoin('sale_payments as sp', 'sp.sales_invoice_id', '=', 'si.id')
            ->select(
                'si.id',
                'si.bill_no',
                'si.date_time',
                'si.status',
                'si.total_amount',
                'si.total_discount_amount',
                'si.total_tax_amount',
                'c.name as customer_name',
                'c.mobile as customer_mobile',
                'f.shop_name as franchisee_name',
                'f.shop_code as franchisee_code'
            )
            ->selectRaw('COALESCE(SUM(sp.cash_amount), 0) as cash_amount')
            ->selectRaw('COALESCE(SUM(sp.bank_amount), 0) as bank_amount')
            ->selectRaw('COALESCE(SUM(sp.credit_amount), 0) as credit_amount')
            ->groupBy(
                'si.id',
                'si.bill_no',
                'si.date_time',
                'si.status',
                'si.total_amount',
                'si.total_discount_amount',
                'si.total_tax_amount',
                'c.name',
                'c.mobile',
                'f.shop_name',
                'f.shop_code'
            )
            ->orderByDesc('si.date_time');

        $invoiceTotals = (clone $query)
            ->selectRaw('COUNT(si.id) as bill_count')
            ->selectRaw('COALESCE(SUM(si.total_amount), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(si.total_discount_amount), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(si.total_tax_amount), 0) as total_tax')
            ->first();

        $paymentTotals = (clone $query)
            ->leftJoin('sale_payments as sp_agg', 'sp_agg.sales_invoice_id', '=', 'si.id')
            ->selectRaw('COALESCE(SUM(sp_agg.cash_amount), 0) as total_cash')
            ->selectRaw('COALESCE(SUM(sp_agg.bank_amount), 0) as total_bank')
            ->selectRaw('COALESCE(SUM(sp_agg.credit_amount), 0) as total_credit')
            ->first();

        $totals = (object) [
            'bill_count' => (int) ($invoiceTotals->bill_count ?? 0),
            'total_sales' => (float) ($invoiceTotals->total_sales ?? 0),
            'total_discount' => (float) ($invoiceTotals->total_discount ?? 0),
            'total_tax' => (float) ($invoiceTotals->total_tax ?? 0),
            'total_cash' => (float) ($paymentTotals->total_cash ?? 0),
            'total_bank' => (float) ($paymentTotals->total_bank ?? 0),
            'total_credit' => (float) ($paymentTotals->total_credit ?? 0),
        ];

        $format = $this->requestedExportFormat($request);
        if ($format) {
            $rows = $rowsQuery->get()->map(function ($row) {
                return [
                    $row->bill_no,
                    Carbon::parse($row->date_time)->format('Y-m-d H:i'),
                    $row->franchisee_name ?: '-',
                    $row->franchisee_code ?: '-',
                    $row->customer_name ?: 'Walk-in',
                    $row->customer_mobile ?: '-',
                    ucfirst((string) $row->status),
                    (float) $row->cash_amount,
                    (float) $row->bank_amount,
                    (float) $row->credit_amount,
                    (float) $row->total_discount_amount,
                    (float) $row->total_tax_amount,
                    (float) $row->total_amount,
                ];
            })->all();

            $headers = [
                'Bill No',
                'Date Time',
                'Franchise',
                'Shop Code',
                'Customer',
                'Mobile',
                'Status',
                'Cash',
                'Bank',
                'Credit',
                'Discount',
                'Tax',
                'Total',
            ];

            $meta = [
                'From Date' => $startDate,
                'To Date' => $endDate,
                'Bills' => (int) ($totals->bill_count ?? 0),
                'Total Sales' => (float) ($totals->total_sales ?? 0),
            ];

            if ($format === 'excel') {
                return $this->reportExportService->downloadExcel(
                    fileBase: 'daily_sales_register',
                    sheetTitle: 'Daily Sales',
                    headers: $headers,
                    rows: $rows,
                    meta: $meta,
                );
            }

            if ($format === 'pdf') {
                return $this->reportExportService->downloadPdf(
                    fileBase: 'daily_sales_register',
                    title: 'Daily Sales Register',
                    headers: $headers,
                    rows: $rows,
                    meta: $meta,
                );
            }

            return response()->streamDownload(function () use ($headers, $rows) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $headers);
                foreach ($rows as $row) {
                    fputcsv($file, $row);
                }
                fclose($file);
            }, 'daily_sales_register_' . date('Ymd_His') . '.csv');
        }

        return Inertia::render('Reports/Sales/DailyRegister', [
            'rows' => $rowsQuery->paginate(30)->withQueryString(),
            'totals' => $totals,
            'filters' => $request->only(['start_date', 'end_date', 'status', 'search']),
        ]);
    }

    // ══════════════════════════════════════
    //  BUSINESS INTELLIGENCE (Enterprise Dashboards)
    // ══════════════════════════════════════

    /**
     * Top selling products by retail volume globally or per territory
     */
    public function topProducts(Request $request)
    {
        $this->authorize('view reports');
        $allowedIds = $this->getAllowedFranchiseeIds($request->user());

        $days = (int)$request->input('days', 30);
        $threshold = Carbon::now()->subDays($days);

        $query = DB::table('sales_invoice_items as sit')
            ->join('sales_invoices as si', 'sit.sales_invoice_id', '=', 'si.id')
            ->join('products as p', 'sit.product_id', '=', 'p.id')
            ->where('si.date_time', '>=', $threshold)
            ->where('si.status', 'completed')
            ->select(
                'p.product_name',
                'p.sku',
                DB::raw('SUM(sit.qty) as total_units_sold'),
                DB::raw('SUM(sit.taxable_amount) as total_revenue')
            )
            ->groupBy('p.id', 'p.product_name', 'p.sku')
            ->orderByDesc('total_revenue')
            ->limit(20);

        if ($allowedIds !== null) {
            $query->whereIn('si.franchisee_id', $allowedIds);
        }

        return Inertia::render('Reports/BI/TopProducts', [
            'products' => $query->get(),
            'days' => $days
        ]);
    }

    /**
     * Commission report — shows all commission entries with hierarchy context.
     * Admin sees all; franchisee/territory heads see their own earnings.
     */
    public function commissions(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTerritoryHead() && !$user->isFranchisee() && !$user->isDistributer()) {
            abort(403);
        }

        $query = DB::table('commissions as c')
            ->join('users as u', 'c.user_id', '=', 'u.id')
            ->leftJoin('dist_orders as d', 'c.dist_order_id', '=', 'd.id')
            ->select(
                'c.id', 'c.type', 'c.cr_dr',
                'c.base_amount', 'c.commission_percent',
                'c.gross_commission', 'c.tds_percent', 'c.tds_amount', 'c.net_payable',
                'c.description', 'c.status', 'c.created_at',
                'u.name as user_name',
                'd.order_no'
            )
            ->latest('c.created_at');

        // Scope: non-admins only see their own commission entries
        if (!$user->isSuperAdmin()) {
            $query->where('c.user_id', $user->id);
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('c.status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('c.type', $request->type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('c.created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('c.created_at', '<=', $request->date_to);
        }
        if ($request->filled('user_id') && $user->isSuperAdmin()) {
            $query->where('c.user_id', $request->user_id);
        }

        $commissions = $query->paginate(30)->withQueryString();

        // Totals for filter window
        $totalsQuery = clone $query->getQuery();
        $totals = $totalsQuery->reorder()->selectRaw('
            SUM(gross_commission) as total_gross,
            SUM(tds_amount) as total_tds,
            SUM(net_payable) as total_net,
            COUNT(*) as total_entries
        ')->first();

        // For admin: list of users who have commissions for filtering
        $users = $user->isSuperAdmin()
            ? DB::table('commissions')->join('users', 'commissions.user_id', '=', 'users.id')
                ->distinct()->select('users.id', 'users.name')->get()
            : collect();

        return Inertia::render('Reports/Commission/Index', [
            'commissions' => $commissions,
            'totals'      => $totals,
            'users'       => $users,
            'filters'     => $request->only(['status', 'type', 'date_from', 'date_to', 'user_id']),
            'isAdmin'     => $user->isSuperAdmin(),
        ]);
    }

    /**
     * Vendor payable outstanding with aging buckets.
     */
    public function vendorOutstanding(Request $request)
    {
        $this->authorize('view reports');
        $user = $request->user();

        // Procurement/finance report: only users handling central finance and purchasing.
        if (!$user->isAdmin() && !$user->isAccount() && !$user->isDistributer()) {
            abort(403);
        }

        $supplierMorph = (new Supplier())->getMorphClass();

        $latestLedgerSub = DB::table('financial_ledgers')
            ->selectRaw('ledgerable_id, MAX(id) as max_id')
            ->where('ledgerable_type', $supplierMorph)
            ->groupBy('ledgerable_id');

        $baseQuery = DB::table('financial_ledgers as fl')
            ->joinSub($latestLedgerSub, 'latest', function ($join) {
                $join->on('latest.max_id', '=', 'fl.id');
            })
            ->join('suppliers as s', 's.id', '=', 'fl.ledgerable_id')
            ->where('fl.ledgerable_type', $supplierMorph)
            ->where('fl.running_balance', '>', 0)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->input('search'));
                $q->where(function ($inner) use ($term) {
                    $inner->where('s.name', 'like', "%{$term}%")
                        ->orWhere('s.code', 'like', "%{$term}%")
                        ->orWhere('s.gst_number', 'like', "%{$term}%")
                        ->orWhere('s.phone', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('min_outstanding'), function ($q) use ($request) {
                $q->where('fl.running_balance', '>=', (float) $request->input('min_outstanding'));
            });

        $totals = (clone $baseQuery)
            ->selectRaw('COUNT(*) as suppliers_with_dues, COALESCE(SUM(fl.running_balance),0) as total_outstanding')
            ->first();

        $rows = (clone $baseQuery)
            ->select(
                's.id as supplier_id',
                's.name',
                's.code',
                's.phone',
                's.gst_number',
                'fl.running_balance as outstanding_balance',
                'fl.transaction_date as ledger_date'
            )
            ->orderByDesc('fl.running_balance')
            ->paginate(30)
            ->withQueryString();

        $supplierIds = collect($rows->items())->pluck('supplier_id')->all();

        $invoiceAgg = collect();
        $returnAgg = collect();
        $paymentAgg = collect();

        if (!empty($supplierIds)) {
            $dueExpr = 'DATE_ADD(invoice_date, INTERVAL COALESCE(due_days, 0) DAY)';

            $invoiceAgg = PurchaseInvoice::query()
                ->approved()
                ->whereIn('supplier_id', $supplierIds)
                ->selectRaw("supplier_id,
                    COALESCE(SUM(total_amount), 0) as gross_invoiced,
                    COALESCE(SUM(CASE WHEN {$dueExpr} >= CURDATE() THEN total_amount ELSE 0 END), 0) as bucket_current,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), {$dueExpr}) BETWEEN 1 AND 30 THEN total_amount ELSE 0 END), 0) as bucket_1_30,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), {$dueExpr}) BETWEEN 31 AND 60 THEN total_amount ELSE 0 END), 0) as bucket_31_60,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), {$dueExpr}) BETWEEN 61 AND 90 THEN total_amount ELSE 0 END), 0) as bucket_61_90,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), {$dueExpr}) > 90 THEN total_amount ELSE 0 END), 0) as bucket_90_plus,
                    COALESCE(MAX(invoice_date), NULL) as last_invoice_date")
                ->groupBy('supplier_id')
                ->get()
                ->keyBy('supplier_id');

            $returnAgg = PurchaseReturn::query()
                ->where('status', 'approved')
                ->whereIn('supplier_id', $supplierIds)
                ->selectRaw('supplier_id, COALESCE(SUM(total_amount), 0) as total_returns')
                ->groupBy('supplier_id')
                ->get()
                ->keyBy('supplier_id');

            $paymentAgg = DB::table('financial_ledgers')
                ->where('ledgerable_type', $supplierMorph)
                ->whereIn('ledgerable_id', $supplierIds)
                ->selectRaw("ledgerable_id as supplier_id,
                    COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT_MADE' THEN debit ELSE 0 END), 0) as total_paid,
                    MAX(CASE WHEN transaction_type = 'PAYMENT_MADE' THEN transaction_date ELSE NULL END) as last_payment_date")
                ->groupBy('ledgerable_id')
                ->get()
                ->keyBy('supplier_id');
        }

        $enriched = collect($rows->items())->map(function ($row) use ($invoiceAgg, $returnAgg, $paymentAgg) {
            $invoice = $invoiceAgg->get($row->supplier_id);
            $returns = $returnAgg->get($row->supplier_id);
            $payments = $paymentAgg->get($row->supplier_id);

            $grossInvoiced = (float) ($invoice->gross_invoiced ?? 0);
            $totalReturns = (float) ($returns->total_returns ?? 0);
            $netInvoiced = max($grossInvoiced - $totalReturns, 0);
            $outstanding = (float) $row->outstanding_balance;

            // Estimated aging by scaling invoice buckets to current outstanding.
            $scale = $netInvoiced > 0 ? min(1, $outstanding / $netInvoiced) : 0;

            return [
                'supplier_id' => (int) $row->supplier_id,
                'name' => $row->name,
                'code' => $row->code,
                'phone' => $row->phone,
                'gst_number' => $row->gst_number,
                'outstanding_balance' => round($outstanding, 2),
                'gross_invoiced' => round($grossInvoiced, 2),
                'total_returns' => round($totalReturns, 2),
                'net_invoiced' => round($netInvoiced, 2),
                'total_paid' => round((float) ($payments->total_paid ?? 0), 2),
                'last_invoice_date' => $invoice?->last_invoice_date,
                'last_payment_date' => $payments?->last_payment_date,
                'aging' => [
                    'current' => round(((float) ($invoice->bucket_current ?? 0)) * $scale, 2),
                    'days_1_30' => round(((float) ($invoice->bucket_1_30 ?? 0)) * $scale, 2),
                    'days_31_60' => round(((float) ($invoice->bucket_31_60 ?? 0)) * $scale, 2),
                    'days_61_90' => round(((float) ($invoice->bucket_61_90 ?? 0)) * $scale, 2),
                    'days_90_plus' => round(((float) ($invoice->bucket_90_plus ?? 0)) * $scale, 2),
                ],
            ];
        })->values();

        $rows->setCollection($enriched);

        $summary = [
            'suppliers_with_dues' => (int) ($totals->suppliers_with_dues ?? 0),
            'total_outstanding' => round((float) ($totals->total_outstanding ?? 0), 2),
            'above_90_days' => round($enriched->sum(fn ($r) => (float) ($r['aging']['days_90_plus'] ?? 0)), 2),
        ];

        return Inertia::render('Reports/Finance/VendorOutstanding', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $request->only(['search', 'min_outstanding']),
        ]);
    }
}

