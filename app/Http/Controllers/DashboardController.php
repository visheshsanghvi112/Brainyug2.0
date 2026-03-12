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
        if ($user->isFranchisee() || $user->hasRole('Franchisee Staff')) {
            return $this->buildFranchiseeDashboard($user);
        }

        if ($user->isDistributor()) {
            return $this->buildDistributorDashboard();
        }

        if ($user->hasRole('Sister Head')) {
            return $this->buildSisterHeadDashboard($user);
        }

        if ($user->hasRole('Payment Manager')) {
            return $this->buildPaymentManagerDashboard();
        }

        if ($user->hasRole('Sales Staff')) {
            return $this->buildSalesStaffDashboard($user);
        }

        return $this->buildAdminDashboard($user);
    }

    private function buildAdminDashboard(User $user): array
    {
        $franchisees = $this->scopedFranchisees($user);
        $orders = $this->scopedOrders($user);
        $scopeLabel = $this->scopeLabel($user);

        $approvedInvoiceValue = PurchaseInvoice::query()
            ->approved()
            ->when(
                !$user->isSuperAdmin(),
                fn (Builder $query) => $query->whereHas('supplier')
            )
            ->sum('total_amount');

        return [
            'title' => $user->isSuperAdmin() ? 'Executive Dashboard' : 'Territory Dashboard',
            'description' => 'Operational health for ' . $scopeLabel . '.',
            'role' => $user->getRoleNames()->first() ?? 'User',
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
                    'value' => $this->formatCount((clone $franchisees)->active()->count()),
                    'context' => 'Live stores inside your operating scope.',
                    'icon' => 'UsersIcon',
                    'tone' => 'emerald',
                    'href' => route('admin.franchisees.index'),
                ],
                [
                    'name' => 'Pending Franchisees',
                    'value' => $this->formatCount((clone $franchisees)->pending()->count()),
                    'context' => 'Applications waiting for approval or activation.',
                    'icon' => 'BuildingStorefrontIcon',
                    'tone' => 'amber',
                    'href' => route('admin.franchisees.index', ['status' => 'registered']),
                ],
                [
                    'name' => 'Open Distribution Orders',
                    'value' => $this->formatCount((clone $orders)->whereIn('status', ['pending', 'accepted'])->count()),
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

        return [
            'title' => $user->hasRole('Franchisee Staff') ? 'Franchise Operations Dashboard' : 'Franchisee Dashboard',
            'description' => $franchiseeId
                ? 'Your local store operations, order pipeline, and catalog access.'
                : 'Your account is not linked to a franchisee record yet. Order tools stay limited until that link exists.',
            'role' => $user->getRoleNames()->first() ?? 'Franchisee',
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
                    'value' => $this->formatCurrency(SalesInvoice::where('franchisee_id', $franchiseeId)->whereDate('created_at', now())->sum('total_amount')),
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

    private function buildDistributorDashboard(): array
    {
        $orders = DistOrder::query();

        return [
            'title' => 'Sales Operations Dashboard',
            'description' => 'Incoming franchise order desk, approval queue, and dispatch execution.',
            'role' => 'Distributor',
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

    private function buildSisterHeadDashboard(User $user): array
    {
        return [
            'title' => 'Sister Head Audit Dashboard',
            'description' => 'Monitoring and support view for network compliance.',
            'role' => 'Sister Head',
            'actions' => [
                $this->action('Franchise Reviews', 'Inspect network-wide franchise performance and compliance.', route('admin.franchisees.index'), 'indigo'),
                $this->action('Support Tickets', 'Review operating issues bubbling up from the field.', route('tickets.index'), 'amber'),
            ],
            'workflows' => [
                $this->workflow('Compliance watch', 'Observe active stores, tickets, and network health.', 'live', route('admin.franchisees.index')),
                $this->workflow('Support escalation', 'Bridge field issues into the central team.', 'active', route('tickets.index')),
            ],
            'stats' => [
                [
                    'name' => 'Active Franchisees',
                    'value' => $this->formatCount(Franchisee::active()->count()),
                    'icon' => 'BuildingStorefrontIcon',
                    'tone' => 'indigo',
                    'href' => route('admin.franchisees.index'),
                ],
                [
                    'name' => 'Daily Network Sales',
                    'value' => $this->formatCurrency(SalesInvoice::whereDate('created_at', now())->sum('total_amount')),
                    'icon' => 'CurrencyRupeeIcon',
                    'tone' => 'emerald',
                ],
            ],
        ];
    }

    private function buildPaymentManagerDashboard(): array
    {
        return [
            'title' => 'Finance & Accounts Dashboard',
            'description' => 'Real-time liquidity and payment reconciliation.',
            'role' => 'Payment Manager',
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
                    'value' => $this->formatCurrency(FinancialLedger::where('cr_dr', 'Dr')->sum('debit') - FinancialLedger::where('cr_dr', 'Cr')->sum('credit')),
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

    private function buildSalesStaffDashboard(User $user): array
    {
        return [
            'title' => 'Field Sales Dashboard',
            'description' => 'Order pipeline and store performance tracking.',
            'role' => 'Sales Staff',
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
}