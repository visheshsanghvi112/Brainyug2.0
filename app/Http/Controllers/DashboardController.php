<?php

namespace App\Http\Controllers;

use App\Models\B2bCart;
use App\Models\DistOrder;
use App\Models\Franchisee;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\SalesInvoice;
use App\Models\FinancialLedger;
use App\Models\Expense;
use App\Support\DashboardAccessPolicy;
use App\Support\DashboardViewProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'dashboard' => $this->buildDashboard($request->user()),
        ]);
    }

    private function buildDashboard(User $user): array
    {
        $profile = DashboardViewProfile::runtimeFor($user);

        $dashboard = match ($profile) {
            DashboardViewProfile::FRANCHISEE => $this->buildFranchiseeDashboard($user),
            DashboardViewProfile::DISTRIBUTER => $this->buildDistributerDashboard(),
            DashboardViewProfile::ACCOUNT => $this->buildAccountDashboard(),
            DashboardViewProfile::SALES_TEAM => $this->buildSalesTeamDashboard($user),
            DashboardViewProfile::EXECUTIVE, DashboardViewProfile::ADMIN => $this->buildAdminDashboard($user),
            default => $this->buildAdminDashboard($user),
        };

        return $this->applySectionVisibilityOverrides($user, $dashboard);
    }

    private function applySectionVisibilityOverrides(User $user, array $dashboard): array
    {
        $dashboardPreferences = data_get($user->preferences ?? [], 'dashboard', []);
        if (! is_array($dashboardPreferences) || ! array_key_exists('sections', $dashboardPreferences)) {
            return $dashboard;
        }

        $sections = $dashboardPreferences['sections'];
        if (! is_array($sections)) {
            $sections = [];
        }

        $allowed = array_values(array_intersect($sections, DashboardAccessPolicy::allSections()));

        if (! in_array(DashboardAccessPolicy::SECTION_TREND, $allowed, true)) {
            $dashboard['trend'] = null;
        }

        if (! in_array(DashboardAccessPolicy::SECTION_PIPELINE, $allowed, true)) {
            $dashboard['pipeline'] = null;
        }

        if (! in_array(DashboardAccessPolicy::SECTION_LEADERBOARD, $allowed, true)) {
            $dashboard['leaderboard'] = [];
        }

        if (! in_array(DashboardAccessPolicy::SECTION_ALERTS, $allowed, true)) {
            $dashboard['alerts'] = [];
        }

        if (! in_array(DashboardAccessPolicy::SECTION_FOCUS, $allowed, true)) {
            $dashboard['focus'] = [];
        }

        if (! in_array(DashboardAccessPolicy::SECTION_ACTIONS, $allowed, true)) {
            $dashboard['actions'] = [];
        }

        if (! in_array(DashboardAccessPolicy::SECTION_WORKFLOWS, $allowed, true)) {
            $dashboard['workflows'] = [];
        }

        if (! in_array(DashboardAccessPolicy::SECTION_STATS, $allowed, true)) {
            $dashboard['stats'] = [];
        }

        return $dashboard;
    }

    private function buildAdminDashboard(User $user): array
    {
        $franchisees = $this->scopedFranchisees($user);
        $orders = $this->scopedOrders($user);
        $scopeLabel = $this->scopeLabel($user);
        $scopedFranchiseeIds = $user->isSuperAdmin() ? null : (clone $franchisees)->pluck('id')->all();

        $approvedInvoiceValue = PurchaseInvoice::query()
            ->approved()
            ->when(
                !$user->isSuperAdmin(),
                fn (Builder $query) => $query->whereHas('supplier')
            )
            ->sum('total_amount');

        $activeFranchisees = (clone $franchisees)->active()->count();
        $pendingFranchisees = (clone $franchisees)->pending()->count();
        $openOrders = (clone $orders)->whereIn('status', ['pending', 'accepted'])->count();
        $riskOrders = (clone $orders)->where('status', 'pending')->where('created_at', '<=', now()->subDays(2))->count();

        return [
            'title' => $user->isAdmin() ? ($user->isSuperAdmin() ? 'Executive Dashboard' : 'Admin Dashboard') : 'Territory Dashboard',
            'description' => 'Operational health for ' . $scopeLabel . '.',
            'role' => $user->canonicalRoleName() ?? 'User',
            'trend' => $this->salesTrend($scopedFranchiseeIds),
            'pipeline' => $this->orderPipeline($orders),
            'leaderboard' => $this->topFranchiseeSales($scopedFranchiseeIds),
            'alerts' => array_values(array_filter([
                $pendingFranchisees > 0 ? $this->alert('high', 'Pending franchise approvals', $pendingFranchisees . ' registrations are waiting for decision.', route('admin.franchise-registrations.index')) : null,
                $riskOrders > 0 ? $this->alert('medium', 'Aging pending orders', $riskOrders . ' orders are pending for more than 48 hours.', route('admin.dist-orders.index', ['status' => 'pending'])) : null,
                $openOrders > 20 ? $this->alert('medium', 'Dispatch queue saturation', 'Open distribution queue has crossed 20 active orders.', route('admin.dist-orders.index')) : null,
            ])),
            'focus' => [
                ['label' => 'Network Coverage', 'value' => $activeFranchisees . ' active stores in scope'],
                ['label' => 'Approval SLA', 'value' => ($pendingFranchisees > 0 ? $pendingFranchisees : 'No') . ' items in approval queue'],
                ['label' => 'Order Throughput', 'value' => $openOrders . ' active orders in process'],
            ],
            'actions' => [
                $this->action('Franchise Network', 'Review registrations, approvals, and active stores.', route('admin.franchisees.index'), 'emerald'),
                $this->action('Distribution Orders', 'Run pending, accepted, and dispatched order operations.', route('admin.dist-orders.index'), 'sky'),
                $this->action('Product Catalog', 'Control products, companies, salts, and tax masters.', route('admin.products.index'), 'indigo'),
                $this->action('Procurement Desk', 'Monitor suppliers and purchase documents.', route('admin.purchase-invoices.index'), 'violet'),
            ],
            'workflows' => [
                $this->workflow('Identity & hierarchy', 'Users, heads, and franchise network governance.', 'live', route('admin.franchisees.index')),
                $this->workflow('Supply & dispatch', 'HO order desk, approvals, and dispatch movement.', 'live', route('admin.dist-orders.index')),
                $this->workflow('Master governance', 'Catalog, tax, and compliance masters.', 'live', route('admin.products.index')),
                $this->workflow('Network reporting', 'Territory performance and finance visibility.', 'active', route('ledger.index')),
            ],
            'stats' => [
                [
                    'name' => 'Active Franchisees',
                    'value' => $this->formatCount($activeFranchisees),
                    'context' => 'Live stores inside your operating scope.',
                    'icon' => 'UsersIcon',
                    'tone' => 'emerald',
                    'href' => route('admin.franchisees.index'),
                ],
                [
                    'name' => 'Pending Franchisees',
                    'value' => $this->formatCount($pendingFranchisees),
                    'context' => 'Applications waiting for approval or activation.',
                    'icon' => 'BuildingStorefrontIcon',
                    'tone' => 'amber',
                    'href' => route('admin.franchisees.index', ['status' => 'registered']),
                ],
                [
                    'name' => 'Open Distribution Orders',
                    'value' => $this->formatCount($openOrders),
                    'context' => 'Orders awaiting action from HO or dispatch.',
                    'icon' => 'TruckIcon',
                    'tone' => 'sky',
                    'href' => route('admin.dist-orders.index'),
                ],
                [
                    'name' => 'Approved Procurement',
                    'value' => $this->formatCurrency($approvedInvoiceValue),
                    'context' => 'Approved purchase invoice value recorded so far.',
                    'icon' => 'CurrencyRupeeIcon',
                    'tone' => 'violet',
                    'href' => route('admin.purchase-invoices.index', ['status' => 'approved']),
                ],
                [
                    'name' => 'Active Catalog Products',
                    'value' => $this->formatCount(Product::query()->where('is_active', true)->count()),
                    'context' => 'Products currently available in the catalog.',
                    'icon' => 'ArchiveBoxIcon',
                    'tone' => 'slate',
                    'href' => route('admin.products.index'),
                ],
                [
                    'name' => 'Suppliers',
                    'value' => $this->formatCount(Supplier::query()->active()->count()),
                    'context' => 'Enabled procurement partners in the system.',
                    'icon' => 'DocumentTextIcon',
                    'tone' => 'indigo',
                    'href' => route('admin.suppliers.index'),
                ],
                [
                    'name' => 'Global Retail Sales',
                    'value' => $this->formatCurrency(SalesInvoice::query()->where('status', 'completed')->sum('total_amount')),
                    'context' => 'Cumulative value of all retail bills generated across franchises.',
                    'icon' => 'CurrencyRupeeIcon',
                    'tone' => 'emerald',
                    'href' => route('ledger.index'),
                ],
                [
                    'name' => 'Network Expenses',
                    'value' => $this->formatCurrency(Expense::sum('total_amount')),
                    'context' => 'Total operational overhead recorded by HO and stores.',
                    'icon' => 'CreditCardIcon',
                    'tone' => 'rose',
                    'href' => route('expenses.index'),
                ],
            ],
        ];
    }

    private function buildFranchiseeDashboard(User $user): array
    {
        $franchiseeId = $user->getEffectiveFranchiseeId();
        $cart = B2bCart::query()
            ->with('items')
            ->where('user_id', $user->id)
            ->when($franchiseeId, fn (Builder $query) => $query->where('franchisee_id', $franchiseeId))
            ->first();

        $orders = DistOrder::query()
            ->where('user_id', $user->id)
            ->when($franchiseeId, fn (Builder $query) => $query->orWhere('franchisee_id', $franchiseeId));

        $todaySales = SalesInvoice::where('franchisee_id', $franchiseeId)->whereDate('created_at', now())->sum('total_amount');
        $pendingOrders = (clone $orders)->where('status', 'pending')->count();
        $recentRejected = (clone $orders)->where('status', 'rejected')->whereDate('updated_at', '>=', now()->subDays(7))->count();

        return [
            'title' => 'Franchise Operations Dashboard',
            'description' => $franchiseeId
                ? 'Your local store operations, order pipeline, and catalog access.'
                : 'Your account is not linked to a franchisee record yet. Order tools stay limited until that link exists.',
            'role' => $user->canonicalRoleName() ?? 'Franchisee',
            'trend' => $franchiseeId ? $this->salesTrend([$franchiseeId]) : null,
            'pipeline' => $this->orderPipeline($orders),
            'leaderboard' => $franchiseeId ? $this->topProductsForFranchise($franchiseeId) : [],
            'alerts' => array_values(array_filter([
                $pendingOrders > 0 ? $this->alert('medium', 'Pending HO approval', $pendingOrders . ' order(s) are still waiting with HO.', route('b2b.cart.index')) : null,
                $recentRejected > 0 ? $this->alert('high', 'Recent rejected orders', $recentRejected . ' orders were rejected in the last 7 days.', route('b2b.cart.index', ['status' => 'rejected'])) : null,
            ])),
            'focus' => [
                ['label' => 'Counter Velocity', 'value' => $this->formatCurrency($todaySales) . ' sold today'],
                ['label' => 'Cart Readiness', 'value' => (int) ($cart?->items->sum('qty') ?? 0) . ' units ready for reorder'],
                ['label' => 'Order Reliability', 'value' => $pendingOrders . ' pending with HO'],
            ],
            'actions' => [
                $this->action('Order From HO', 'Build the next stock request from the live catalog.', route('b2b.cart.index'), 'sky'),
                $this->action('Retail POS', 'Generate bills and run day-to-day counter sales.', route('pos.index'), 'emerald'),
                $this->action('Sales Invoices', 'Review issued bills and cash collection history.', route('pos.invoices.index'), 'violet'),
                $this->action('Customer Directory', 'Manage repeat buyers, doctors, and store-side relationships.', route('customers.index'), 'indigo'),
            ],
            'workflows' => [
                $this->workflow('B2B replenishment', 'Search products, fill cart, and submit stock orders to HO.', 'live', route('b2b.cart.index')),
                $this->workflow('Store billing', 'Run POS, print bills, and handle daily retail sales.', 'live', route('pos.index')),
                $this->workflow('Store reporting', 'Track invoices, returns, and ledger position.', 'active', route('ledger.index')),
                $this->workflow('Franchise operations', 'Customer, return, and stock-facing ERP surface.', 'build-next', route('customers.index')),
            ],
            'stats' => [
                [
                    'name' => 'Catalog Products',
                    'value' => $this->formatCount(Product::query()->where('is_active', true)->count()),
                    'context' => 'Active products available for ordering.',
                    'icon' => 'ArchiveBoxIcon',
                    'tone' => 'slate',
                    'href' => route('b2b.cart.index'),
                ],
                [
                    'name' => 'Items In Cart',
                    'value' => $this->formatCount((int) ($cart?->items->sum('qty') ?? 0)),
                    'context' => 'Units currently staged for the next B2B order.',
                    'icon' => 'ShoppingBagIcon',
                    'tone' => 'sky',
                    'href' => route('b2b.cart.index'),
                ],
                [
                    'name' => 'Orders Awaiting HO',
                    'value' => $this->formatCount((clone $orders)->where('status', 'pending')->count()),
                    'context' => 'Orders submitted and waiting for HO acceptance.',
                    'icon' => 'ClockIcon',
                    'tone' => 'amber',
                    'href' => route('b2b.cart.index'),
                ],
                [
                    'name' => 'Orders Dispatched',
                    'value' => $this->formatCount((clone $orders)->where('status', 'dispatched')->count()),
                    'context' => 'Orders already sent from HO to your store.',
                    'icon' => 'TruckIcon',
                    'tone' => 'emerald',
                    'href' => route('b2b.cart.index'),
                ],
                [
                    'name' => 'Today\'s POS Sales',
                    'value' => $this->formatCurrency($todaySales),
                    'context' => 'Daily retail collection at your counter.',
                    'icon' => 'CurrencyRupeeIcon',
                    'tone' => 'emerald',
                    'href' => route('pos.index'),
                ],
                [
                    'name' => 'Account Balance',
                    'value' => $this->formatCurrency(FinancialLedger::where('ledgerable_type', Franchisee::class)->where('ledgerable_id', $franchiseeId)->latest('id')->value('running_balance') ?? 0),
                    'context' => 'Your current credit/debit standing with HO.',
                    'icon' => 'WalletIcon',
                    'tone' => 'indigo',
                    'href' => route('ledger.index'),
                ],
            ],
        ];
    }

    private function buildDistributerDashboard(): array
    {
        $orders = DistOrder::query();
        $pending = (clone $orders)->where('status', 'pending')->count();
        $accepted = (clone $orders)->where('status', 'accepted')->count();
        $agedAccepted = (clone $orders)->where('status', 'accepted')->where('accepted_at', '<=', now()->subDays(1))->count();

        return [
            'title' => 'Distributer Order Desk',
            'description' => 'Incoming franchise order desk, approval queue, and dispatch execution.',
            'role' => 'Distributer',
            'trend' => $this->salesTrend(null),
            'pipeline' => $this->orderPipeline($orders),
            'leaderboard' => $this->topFranchiseeSales(null),
            'alerts' => array_values(array_filter([
                $pending > 15 ? $this->alert('medium', 'High pending queue', $pending . ' orders are waiting for initial review.', route('admin.dist-orders.index', ['status' => 'pending'])) : null,
                $agedAccepted > 0 ? $this->alert('high', 'Dispatch delay risk', $agedAccepted . ' accepted order(s) are pending dispatch beyond 24h.', route('admin.dist-orders.index', ['status' => 'accepted'])) : null,
            ])),
            'focus' => [
                ['label' => 'Intake', 'value' => $pending . ' pending approvals'],
                ['label' => 'Dispatch Queue', 'value' => $accepted . ' ready-to-dispatch orders'],
                ['label' => 'Backlog Risk', 'value' => $agedAccepted . ' accepted beyond 24h'],
            ],
            'actions' => [
                $this->action('Pending Orders', 'Review newly submitted franchise orders.', route('admin.dist-orders.index', ['status' => 'pending']), 'amber'),
                $this->action('Dispatch Queue', 'Move accepted orders into shipment execution.', route('admin.dist-orders.index', ['status' => 'accepted']), 'sky'),
                $this->action('Purchase Invoices', 'Track procurement and replenishment intake.', route('admin.purchase-invoices.index'), 'violet'),
                $this->action('Supplier Base', 'Manage upstream procurement partners.', route('admin.suppliers.index'), 'indigo'),
            ],
            'workflows' => [
                $this->workflow('Order desk', 'Accept, reject, and triage incoming franchise demand.', 'live', route('admin.dist-orders.index')),
                $this->workflow('Dispatch movement', 'Allocate and ship accepted orders from HO.', 'live', route('admin.dist-orders.index', ['status' => 'accepted'])),
                $this->workflow('Procurement support', 'Feed inventory through supplier-side documents.', 'active', route('admin.purchase-invoices.index')),
            ],
            'stats' => [
                [
                    'name' => 'Pending Acceptance',
                    'value' => $this->formatCount((clone $orders)->where('status', 'pending')->count()),
                    'context' => 'Orders that still need batch allocation and approval.',
                    'icon' => 'ClockIcon',
                    'tone' => 'amber',
                    'href' => route('admin.dist-orders.index', ['status' => 'pending']),
                ],
                [
                    'name' => 'Ready To Dispatch',
                    'value' => $this->formatCount((clone $orders)->where('status', 'accepted')->count()),
                    'context' => 'Accepted orders that can move to logistics.',
                    'icon' => 'TruckIcon',
                    'tone' => 'sky',
                    'href' => route('admin.dist-orders.index', ['status' => 'accepted']),
                ],
                [
                    'name' => 'Dispatched Orders',
                    'value' => $this->formatCount((clone $orders)->where('status', 'dispatched')->count()),
                    'context' => 'Orders already transferred out of HO inventory.',
                    'icon' => 'CheckCircleIcon',
                    'tone' => 'emerald',
                    'href' => route('admin.dist-orders.index', ['status' => 'dispatched']),
                ],
                [
                    'name' => 'Active Suppliers',
                    'value' => $this->formatCount(Supplier::query()->active()->count()),
                    'context' => 'Suppliers currently available for replenishment.',
                    'icon' => 'DocumentTextIcon',
                    'tone' => 'indigo',
                    'href' => route('admin.suppliers.index'),
                ],
            ],
        ];
    }

    private function buildAccountDashboard(): array
    {
        $supplierOutstanding = $this->latestLedgerBalanceTotal((new Supplier())->getMorphClass(), positiveOnly: true);
        $franchiseeReceivableNet = $this->latestLedgerBalanceTotal((new Franchisee())->getMorphClass());

        $overdueSupplierInvoices = (int) PurchaseInvoice::query()
            ->approved()
            ->whereRaw('DATE_ADD(invoice_date, INTERVAL COALESCE(due_days, 0) DAY) < CURDATE()')
            ->count();

        return [
            'title' => 'Finance & Accounts Dashboard',
            'description' => 'Real-time liquidity and payment reconciliation.',
            'role' => 'Account',
            'trend' => $this->salesTrend(null),
            'pipeline' => null,
            'leaderboard' => [],
            'alerts' => array_values(array_filter([
                $overdueSupplierInvoices > 0 ? $this->alert('high', 'Supplier dues overdue', $overdueSupplierInvoices . ' supplier invoice(s) are beyond due date.', route('reports.finance.vendor-outstanding')) : null,
                $supplierOutstanding > 0 ? $this->alert('medium', 'Outstanding supplier exposure', 'Current supplier payable exposure is ' . $this->formatCurrency($supplierOutstanding) . '.', route('reports.finance.vendor-outstanding')) : null,
            ])),
            'focus' => [
                ['label' => 'Receivable Net', 'value' => $this->formatCurrency($franchiseeReceivableNet)],
                ['label' => 'Supplier Exposure', 'value' => $this->formatCurrency($supplierOutstanding)],
                ['label' => 'Overdue Vendor Bills', 'value' => $overdueSupplierInvoices . ' invoices'],
            ],
            'actions' => [
                $this->action('General Ledger', 'Review receivables, payables, and ledger movements.', route('ledger.index'), 'amber'),
                $this->action('Expenses', 'Validate network payouts and operating expense entries.', route('expenses.index'), 'rose'),
            ],
            'workflows' => [
                $this->workflow('Collections health', 'Track network balance and outstanding movement.', 'live', route('ledger.index')),
                $this->workflow('Expense control', 'Monitor cost leakage and payout discipline.', 'active', route('expenses.index')),
            ],
            'stats' => [
                [
                    'name' => 'Total Receivables',
                    'value' => $this->formatCurrency($franchiseeReceivableNet),
                    'context' => 'Net outstanding from the franchisee network.',
                    'icon' => 'WalletIcon',
                    'tone' => 'amber',
                    'href' => route('ledger.index'),
                ],
                [
                    'name' => 'Recent Expenses',
                    'value' => $this->formatCurrency(Expense::whereDate('created_at', '>=', now()->subDays(7))->sum('total_amount')),
                    'context' => 'Total payouts in the last 7 days.',
                    'icon' => 'CreditCardIcon',
                    'tone' => 'rose',
                    'href' => route('expenses.index'),
                ],
            ],
        ];
    }

    private function latestLedgerBalanceTotal(string $ledgerableType, bool $positiveOnly = false): float
    {
        $latestLedgerSub = DB::table('financial_ledgers')
            ->selectRaw('ledgerable_id, MAX(id) as max_id')
            ->where('ledgerable_type', $ledgerableType)
            ->groupBy('ledgerable_id');

        $query = DB::table('financial_ledgers as fl')
            ->joinSub($latestLedgerSub, 'latest', function ($join) {
                $join->on('latest.max_id', '=', 'fl.id');
            })
            ->where('fl.ledgerable_type', $ledgerableType);

        if ($positiveOnly) {
            $query->where('fl.running_balance', '>', 0);
        }

        return (float) $query->sum('fl.running_balance');
    }

    private function buildSalesTeamDashboard(User $user): array
    {
        $scopedFranchiseeIds = (clone $this->scopedFranchisees($user))->pluck('id')->all();

        return [
            'title' => 'Field Sales Dashboard',
            'description' => 'Order pipeline and store performance tracking.',
            'role' => 'Sales Team',
            'trend' => $this->salesTrend($scopedFranchiseeIds),
            'pipeline' => $this->orderPipeline($this->scopedOrders($user)),
            'leaderboard' => $this->topFranchiseeSales($scopedFranchiseeIds),
            'alerts' => array_values(array_filter([
                DistOrder::where('status', 'pending')->count() > 10
                    ? $this->alert('medium', 'Pending demand surge', 'Pending orders have crossed double digits. Prioritize callouts.', route('admin.dist-orders.index', ['status' => 'pending']))
                    : null,
            ])),
            'focus' => [
                ['label' => 'Territory Coverage', 'value' => $this->formatCount(count($scopedFranchiseeIds)) . ' stores mapped'],
                ['label' => 'Order Momentum', 'value' => $this->formatCount(DistOrder::whereDate('created_at', '>=', now()->subDays(7))->count()) . ' orders in 7d'],
                ['label' => 'New Growth', 'value' => $this->formatCount(Franchisee::whereDate('created_at', '>=', now()->subDays(30))->count()) . ' stores onboarded in 30d'],
            ],
            'actions' => [
                $this->action('Distribution Orders', 'See what stores are demanding right now.', route('admin.dist-orders.index'), 'sky'),
                $this->action('Franchise Network', 'Track stores, openings, and regional activity.', route('admin.franchisees.index'), 'indigo'),
                $this->action('Product Catalog', 'Use the live catalog during field discussions and onboarding.', route('admin.products.index'), 'emerald'),
            ],
            'workflows' => [
                $this->workflow('Field pipeline', 'Follow pending orders and new franchise traction.', 'live', route('admin.dist-orders.index')),
                $this->workflow('Network expansion', 'Monitor which stores are new, weak, or ready for support.', 'active', route('admin.franchisees.index')),
            ],
            'stats' => [
                [
                    'name' => 'Pending Orders',
                    'value' => $this->formatCount(DistOrder::where('status', 'pending')->count()),
                    'icon' => 'ClockIcon',
                    'tone' => 'sky',
                    'href' => route('admin.dist-orders.index'),
                ],
                [
                    'name' => 'New Franchisees',
                    'value' => $this->formatCount(Franchisee::whereDate('created_at', '>=', now()->subDays(30))->count()),
                    'icon' => 'UsersIcon',
                    'tone' => 'indigo',
                    'href' => route('admin.franchisees.index'),
                ],
            ],
        ];
    }

    private function scopedFranchisees(User $user): Builder
    {
        $query = Franchisee::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isStateHead()) {
            return $query->whereIn('state_id', $user->assignedStateIds());
        }

        if ($user->isRegionalHead()) {
            return $query->whereIn('district_id', $user->assignedDistrictIds());
        }

        if ($user->isZoneHead()) {
            return $query->where('zone_head_id', $user->id);
        }

        if ($user->isDistrictHead()) {
            return $query->where('district_head_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function scopedOrders(User $user): Builder
    {
        $query = DistOrder::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isStateHead()) {
            return $query->whereHas('franchisee', fn (Builder $franchisees) => $franchisees->whereIn('state_id', $user->assignedStateIds()));
        }

        if ($user->isRegionalHead()) {
            return $query->whereHas('franchisee', fn (Builder $franchisees) => $franchisees->whereIn('district_id', $user->assignedDistrictIds()));
        }

        if ($user->isZoneHead()) {
            return $query->whereHas('franchisee', fn (Builder $franchisees) => $franchisees->where('zone_head_id', $user->id));
        }

        if ($user->isDistrictHead()) {
            return $query->whereHas('franchisee', fn (Builder $franchisees) => $franchisees->where('district_head_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    private function scopeLabel(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return 'the full network';
        }

        if ($user->isStateHead()) {
            return 'your assigned state territories';
        }

        if ($user->isRegionalHead()) {
            return 'your assigned regional districts';
        }

        if ($user->isZoneHead()) {
            return 'your managed zone';
        }

        if ($user->isDistrictHead()) {
            return 'your assigned district';
        }

        return 'your scope';
    }

    private function formatCount(int $value): string
    {
        return number_format($value);
    }

    private function formatCurrency(float|int|string|null $value): string
    {
        return '₹' . number_format((float) $value, 2);
    }

    private function action(string $title, string $description, string $href, string $tone = 'indigo'): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'href' => $href,
            'tone' => $tone,
        ];
    }

    private function workflow(string $name, string $description, string $status, ?string $href = null): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'href' => $href,
        ];
    }

    private function alert(string $severity, string $title, string $message, ?string $href = null): array
    {
        return [
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'href' => $href,
        ];
    }

    private function salesTrend(?array $franchiseeIds, int $days = 7): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $query = SalesInvoice::query()
            ->where('status', 'completed')
            ->where('date_time', '>=', $start);

        if (is_array($franchiseeIds)) {
            if ($franchiseeIds === []) {
                return ['labels' => [], 'series' => [], 'total' => 0, 'avg' => 0];
            }
            $query->whereIn('franchisee_id', $franchiseeIds);
        }

        $rows = $query
            ->selectRaw('DATE(date_time) as day, SUM(total_amount) as value')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->pluck('value', 'day');

        $labels = [];
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = now()->subDays($days - 1 - $i);
            $key = $day->toDateString();
            $labels[] = $day->format('d M');
            $series[] = round((float) ($rows[$key] ?? 0), 2);
        }

        $total = array_sum($series);

        return [
            'labels' => $labels,
            'series' => $series,
            'total' => round($total, 2),
            'avg' => round($days > 0 ? $total / $days : 0, 2),
        ];
    }

    private function orderPipeline(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0),
            'accepted' => (int) ($rows['accepted'] ?? 0),
            'dispatched' => (int) ($rows['dispatched'] ?? 0),
            'delivered' => (int) ($rows['delivered'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'cancelled' => (int) ($rows['cancelled'] ?? 0),
        ];
    }

    private function topFranchiseeSales(?array $franchiseeIds, int $days = 30, int $limit = 6): array
    {
        $query = DB::table('sales_invoices as si')
            ->join('franchisees as f', 'f.id', '=', 'si.franchisee_id')
            ->where('si.status', 'completed')
            ->where('si.date_time', '>=', now()->subDays($days))
            ->selectRaw('f.id, f.shop_name, f.shop_code, SUM(si.total_amount) as sales, COUNT(si.id) as bills')
            ->groupBy('f.id', 'f.shop_name', 'f.shop_code')
            ->orderByDesc('sales')
            ->limit($limit);

        if (is_array($franchiseeIds)) {
            if ($franchiseeIds === []) {
                return [];
            }
            $query->whereIn('si.franchisee_id', $franchiseeIds);
        }

        return $query->get()->map(function ($row) {
            return [
                'name' => $row->shop_name,
                'code' => $row->shop_code,
                'value' => $this->formatCurrency($row->sales),
                'meta' => (int) $row->bills . ' bills',
            ];
        })->all();
    }

    private function topProductsForFranchise(int $franchiseeId, int $days = 30, int $limit = 6): array
    {
        return DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->join('products as p', 'p.id', '=', 'sii.product_id')
            ->where('si.status', 'completed')
            ->where('si.franchisee_id', $franchiseeId)
            ->where('si.date_time', '>=', now()->subDays($days))
            ->selectRaw('p.product_name, p.sku, SUM(sii.qty + COALESCE(sii.free_qty,0)) as units')
            ->groupBy('p.product_name', 'p.sku')
            ->orderByDesc('units')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->product_name,
                    'code' => $row->sku,
                    'value' => number_format((float) $row->units, 2) . ' units',
                    'meta' => 'Top mover',
                ];
            })->all();
    }
}