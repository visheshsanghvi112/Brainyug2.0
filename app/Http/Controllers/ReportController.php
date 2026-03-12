<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\SalesInvoice;
use App\Models\PurchaseInvoice;
use App\Models\Commission;
use App\Models\Franchisee;
use App\Models\User;
use App\Models\DistOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Get allowed franchisee IDs based on user hierarchy.
     * Returns null if user has unrestricted access.
     */
    private function getAllowedFranchiseeIds(User $user): ?array
    {
        if ($user->hasRole(['Super Admin', 'Payment Manager', 'Distributor', 'Sister Head'])) {
            return null; // Unrestricted
        }

        if ($user->hasRole('State Head')) {
            return $user->managedFranchiseesSH()->pluck('id')->toArray();
        }

        if ($user->hasRole('Zone Head')) {
            return $user->managedFranchiseesZH()->pluck('id')->toArray();
        }

        if ($user->hasRole('District Head')) {
            return $user->managedFranchiseesDH()->pluck('id')->toArray();
        }

        if ($user->hasRole('Franchisee') || $user->hasRole('Franchisee Staff')) {
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
            abort_if($user->hasRole('Franchisee'), 403, 'Franchisees cannot view HO stock directly here.');
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

        // 1. Retail Sales (Franchisee -> Customer B2C)
        $retailQuery = DB::table('sales_invoice_items as sit')
            ->join('sales_invoices as si', 'sit.sales_invoice_id', '=', 'si.id')
            ->join('products as p', 'sit.product_id', '=', 'p.id')
            ->join('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('si.date_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('si.status', 'completed')
            ->select(
                'h.hsn_code',
                DB::raw('SUM(sit.taxable_amount) as taxable_value'),
                DB::raw('sit.gst_percent as rate'),
                DB::raw('SUM(sit.gst_amount) as total_gst'),
                DB::raw("'B2C' as supply_type")
            );

        if ($allowedIds !== null) {
            $retailQuery->whereIn('si.franchisee_id', $allowedIds);
        }

        // 2. Head Office Dispatch (HO -> Franchisee B2B)
        // Note: For franchisees, their outward supply is only Retail. 
        // HO Outward supply is B2B dispatches.
        $hoOutwardQuery = DB::table('dist_order_items as doi')
            ->join('dist_orders as do', 'doi.dist_order_id', '=', 'do.id')
            ->join('products as p', 'doi.product_id', '=', 'p.id')
            ->join('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('do.dispatched_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('do.status', 'dispatched') // completed dispatch
            ->select(
                'h.hsn_code',
                DB::raw('SUM(doi.qty * doi.rate) as taxable_value'), // Simplification: Ensure DB schema logic aligns
                DB::raw('0 as rate'), // Ensure proper join with GST logic later
                DB::raw('SUM((doi.qty * doi.rate) * 0.05) as total_gst'), // Mock calculation unless DB stores precise GST line item
                DB::raw("'B2B' as supply_type")
            );

        // Security: Franchisees do not execute B2B HO supply reports, only Admins
        if ($allowedIds !== null) {
             // For Franchisees, we just return an empty set to union if they try to fetch.
             $hoOutwardQuery->whereRaw('1 = 0'); 
        }

        // We fetch retail sales as primary, B2B logic will be fully mapped once DistOrder tables contain explicit tax lines.
        // For production robustness, we will map standard HSN groupings here for the Retail component.
        
        $results = $retailQuery->groupBy('h.hsn_code', 'sit.gst_percent')->get();

        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($results) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['HSN Code', 'Supply Type', 'Tax Rate (%)', 'Taxable Value', 'Total GST']);
                foreach ($results as $row) {
                    fputcsv($file, [
                        $row->hsn_code,
                        $row->supply_type,
                        $row->rate,
                        $row->taxable_value,
                        $row->total_gst
                    ]);
                }
                fclose($file);
            }, "gstr1_outward_" . date('Ymd_His') . ".csv");
        }

        return Inertia::render('Reports/GST/Gstr1', [
            'retail_sales' => $results,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
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

        // For HO: Inward is Purchase Invoices from Suppliers
        $purchasesQuery = DB::table('purchase_invoice_items as pit')
            ->join('purchase_invoices as pi', 'pit.purchase_invoice_id', '=', 'pi.id')
            ->join('products as p', 'pit.product_id', '=', 'p.id')
            ->join('hsn_masters as h', 'p.hsn_id', '=', 'h.id')
            ->whereBetween('pi.invoice_date', [$startDate, $endDate])
            ->where('pi.status', 'approved')
            ->select(
                'h.hsn_code',
                DB::raw('SUM(pit.taxable_amount) as taxable_value'),
                DB::raw('pit.gst_percent as rate'),
                DB::raw('SUM(pit.gst_amount) as total_gst')
            );
            
        // If a Franchisee is logged in, their inward supply is mostly DISPATCHED orders from HO to them.
        // For this robust phase, if user is restricted, we nullify supplier purchase invoices.
        if ($allowedIds !== null) {
            $purchasesQuery->whereRaw('1 = 0');
        }

        $purchases = $purchasesQuery->groupBy('h.hsn_code', 'pit.gst_percent')->get();

        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($purchases) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['HSN Code', 'Tax Rate (%)', 'Taxable Value', 'Input Tax (ITC)']);
                foreach ($purchases as $row) {
                    fputcsv($file, [
                        $row->hsn_code,
                        $row->rate,
                        $row->taxable_value,
                        $row->total_gst
                    ]);
                }
                fclose($file);
            }, "gstr2_inward_" . date('Ymd_His') . ".csv");
        }

        return Inertia::render('Reports/GST/Gstr2', [
            'purchases' => $purchases,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
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

        if (!$user->hasRole(['Super Admin', 'State Head', 'Zone Head', 'District Head', 'Franchisee', 'Distributor'])) {
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
        if (!$user->hasRole(['Super Admin'])) {
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
        if ($request->filled('user_id') && $user->hasRole('Super Admin')) {
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
        $users = $user->hasRole('Super Admin')
            ? DB::table('commissions')->join('users', 'commissions.user_id', '=', 'users.id')
                ->distinct()->select('users.id', 'users.name')->get()
            : collect();

        return Inertia::render('Reports/Commission/Index', [
            'commissions' => $commissions,
            'totals'      => $totals,
            'users'       => $users,
            'filters'     => $request->only(['status', 'type', 'date_from', 'date_to', 'user_id']),
            'isAdmin'     => $user->hasRole('Super Admin'),
        ]);
    }
}

