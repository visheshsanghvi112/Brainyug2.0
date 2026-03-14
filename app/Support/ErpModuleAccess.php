<?php

namespace App\Support;

use App\Models\User;

final class ErpModuleAccess
{
    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    private const MODULES = [
        'dashboard' => [
            'label' => 'Dashboard',
            'category' => 'Main',
            'description' => 'Default home dashboard surface.',
        ],
        'users' => [
            'label' => 'User Management',
            'category' => 'System Masters',
            'description' => 'Create and manage ERP users.',
        ],
        'support_access' => [
            'label' => 'Support Access Audit',
            'category' => 'System Masters',
            'description' => 'View support impersonation audit trail.',
        ],
        'franchise_registrations' => [
            'label' => 'Registration Queue',
            'category' => 'System Masters',
            'description' => 'Review and approve franchise registrations.',
        ],
        'franchisees' => [
            'label' => 'Franchise Network',
            'category' => 'System Masters',
            'description' => 'Manage franchise records and status.',
        ],
        'products' => [
            'label' => 'Product Catalog',
            'category' => 'Product Master',
            'description' => 'Manage products and stock-facing catalog data.',
        ],
        'hsn_masters' => [
            'label' => 'HSN & Tax Maps',
            'category' => 'Product Master',
            'description' => 'Manage HSN and GST tax mapping.',
        ],
        'salt_masters' => [
            'label' => 'Salt & Drugs',
            'category' => 'Product Master',
            'description' => 'Manage salt/composition masters.',
        ],
        'categories' => [
            'label' => 'Item Categories',
            'category' => 'Product Master',
            'description' => 'Manage item category masters.',
        ],
        'companies' => [
            'label' => 'Company Masters',
            'category' => 'Product Master',
            'description' => 'Manage pharma company masters.',
        ],
        'rack_layout' => [
            'label' => 'Rack Layout',
            'category' => 'Product Master',
            'description' => 'Manage rack sections and rack areas.',
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'category' => 'Procurement',
            'description' => 'Manage suppliers and supplier payments.',
        ],
        'purchase_invoices' => [
            'label' => 'Purchase Invoices',
            'category' => 'Procurement',
            'description' => 'Manage purchase invoice lifecycle.',
        ],
        'purchase_returns' => [
            'label' => 'Purchase Returns',
            'category' => 'Procurement',
            'description' => 'Manage purchase return lifecycle.',
        ],
        'stock_adjustment' => [
            'label' => 'Stock Adjustment',
            'category' => 'Procurement',
            'description' => 'Manual stock corrections and inventory audit.',
        ],
        'b2b_cart' => [
            'label' => 'Order from HO (Cart)',
            'category' => 'Operations',
            'description' => 'Franchisee B2B cart and checkout.',
        ],
        'dist_orders' => [
            'label' => 'Distribution Orders',
            'category' => 'Operations',
            'description' => 'Manage B2B order acceptance and dispatch.',
        ],
        'pos' => [
            'label' => 'Retail POS',
            'category' => 'Operations',
            'description' => 'Retail billing and invoice operations.',
        ],
        'sales_returns' => [
            'label' => 'Sales Returns',
            'category' => 'Operations',
            'description' => 'Sales return processing.',
        ],
        'customers' => [
            'label' => 'Customer Directory',
            'category' => 'Operations',
            'description' => 'Manage store customers and doctors.',
        ],
        'franchise_staff' => [
            'label' => 'Franchise Staff',
            'category' => 'Operations',
            'description' => 'Manage franchise-linked staff accounts.',
        ],
        'tickets' => [
            'label' => 'Support Tickets',
            'category' => 'Communication',
            'description' => 'Ticket create/reply/status operations.',
        ],
        'meetings' => [
            'label' => 'Meetings',
            'category' => 'Communication',
            'description' => 'Meeting scheduling and participation.',
        ],
        'shop_visits' => [
            'label' => 'Shop Visit Audits',
            'category' => 'Communication',
            'description' => 'Store visit audit workflows.',
        ],
        'ledger' => [
            'label' => 'General Ledger',
            'category' => 'Accounts',
            'description' => 'Financial ledger and accounting visibility.',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'category' => 'Accounts',
            'description' => 'Expense recording and review.',
        ],
        'reports_stock' => [
            'label' => 'Inventory & Stock Reports',
            'category' => 'Reports',
            'description' => 'Stock reports and current inventory analytics.',
        ],
        'reports_sales' => [
            'label' => 'Daily Sales Register',
            'category' => 'Reports',
            'description' => 'Daily sales register and sales trend reports.',
        ],
        'reports_gst' => [
            'label' => 'GST Compliance',
            'category' => 'Reports',
            'description' => 'GSTR reports and tax compliance output.',
        ],
        'reports_finance' => [
            'label' => 'Vendor Outstanding',
            'category' => 'Reports',
            'description' => 'Finance payable and outstanding reports.',
        ],
        'reports_bi' => [
            'label' => 'MIS Dashboards',
            'category' => 'Reports',
            'description' => 'BI and top product reporting.',
        ],
        'reports_commissions' => [
            'label' => 'Commissions',
            'category' => 'Reports',
            'description' => 'Commission report visibility.',
        ],
    ];

    public static function modules(): array
    {
        return self::MODULES;
    }

    public static function actions(): array
    {
        return self::ACTIONS;
    }

    public static function permissionName(string $module, string $action): string
    {
        return sprintf('module.%s.%s', $module, $action);
    }

    public static function allPermissionNames(): array
    {
        $permissions = [];

        foreach (array_keys(self::MODULES) as $module) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = self::permissionName($module, $action);
            }
        }

        return $permissions;
    }

    public static function moduleOptions(): array
    {
        $options = [];

        foreach (self::MODULES as $key => $meta) {
            $permissions = [];

            foreach (self::ACTIONS as $action) {
                $permissions[$action] = self::permissionName($key, $action);
            }

            $options[] = [
                'key' => $key,
                'label' => $meta['label'],
                'category' => $meta['category'],
                'description' => $meta['description'],
                'permissions' => $permissions,
            ];
        }

        return $options;
    }

    public static function normalizeSubmittedMatrix(array $raw): array
    {
        $normalized = [];

        foreach (self::MODULES as $module => $_meta) {
            $normalized[$module] = [];

            foreach (self::ACTIONS as $action) {
                $normalized[$module][$action] = (bool) data_get($raw, $module.'.'.$action, false);
            }

            $normalized[$module] = self::applyActionDependencies($normalized[$module]);
        }

        return $normalized;
    }

    public static function emptyMatrix(): array
    {
        $matrix = [];

        foreach (self::MODULES as $module => $_meta) {
            $matrix[$module] = [
                'view' => false,
                'create' => false,
                'update' => false,
                'delete' => false,
            ];
        }

        return $matrix;
    }

    public static function roleMatrix(string $roleName): array
    {
        $matrix = self::emptyMatrix();

        $role = \Spatie\Permission\Models\Role::query()->where('name', $roleName)->first();
        if (!$role) {
            return $matrix;
        }

        $permissionNames = $role->permissions()->pluck('name')->all();
        $permissionIndex = array_flip($permissionNames);

        foreach (array_keys(self::MODULES) as $module) {
            foreach (self::ACTIONS as $action) {
                $permission = self::permissionName($module, $action);
                $matrix[$module][$action] = isset($permissionIndex[$permission]);
            }

            $matrix[$module] = self::applyActionDependencies($matrix[$module]);
        }

        return $matrix;
    }

    public static function effectiveMatrixFor(User $user): array
    {
        $base = [];

        foreach (self::MODULES as $module => $_meta) {
            $base[$module] = [];

            foreach (self::ACTIONS as $action) {
                $base[$module][$action] = $user->can(self::permissionName($module, $action));
            }
        }

        $overrides = data_get($user->preferences ?? [], 'module_access');
        if (!is_array($overrides)) {
            return $base;
        }

        foreach (self::MODULES as $module => $_meta) {
            foreach (self::ACTIONS as $action) {
                if (data_get($overrides, $module.'.'.$action) !== null) {
                    $base[$module][$action] = (bool) data_get($overrides, $module.'.'.$action);
                }
            }

            $base[$module] = self::applyActionDependencies($base[$module]);
        }

        return $base;
    }

    private static function applyActionDependencies(array $row): array
    {
        $normalizedRow = [
            'view' => (bool) ($row['view'] ?? false),
            'create' => (bool) ($row['create'] ?? false),
            'update' => (bool) ($row['update'] ?? false),
            'delete' => (bool) ($row['delete'] ?? false),
        ];

        if ($normalizedRow['create'] || $normalizedRow['update'] || $normalizedRow['delete']) {
            $normalizedRow['view'] = true;
        }

        return $normalizedRow;
    }

    public static function can(User $user, string $module, string $action = 'view'): bool
    {
        if (!array_key_exists($module, self::MODULES) || !in_array($action, self::ACTIONS, true)) {
            return false;
        }

        return (bool) data_get(self::effectiveMatrixFor($user), $module.'.'.$action, false);
    }

    public static function canAccessRoute(User $user, string $routeName): bool
    {
        foreach (self::routeRequirements() as $prefix => $requirement) {
            if (str_starts_with($routeName, $prefix)) {
                return self::can($user, $requirement['module'], $requirement['action']);
            }
        }

        return true;
    }

    public static function canAccessRouteWithMethod(User $user, string $routeName, string $method): bool
    {
        $requirement = self::requiredForRoute($routeName, $method);
        if ($requirement === null) {
            return true;
        }

        return self::can($user, $requirement['module'], $requirement['action']);
    }

    public static function requiredForRoute(string $routeName, string $method): ?array
    {
        foreach (self::routeRequirements() as $prefix => $requirement) {
            if (str_starts_with($routeName, $prefix)) {
                return [
                    'module' => $requirement['module'],
                    'action' => self::actionFromRouteAndMethod($routeName, $method),
                ];
            }
        }

        return null;
    }

    public static function isProtectedRouteName(string $routeName): bool
    {
        if ($routeName === '') {
            return false;
        }

        $prefixes = (array) config('erp.module_access.protected_route_prefixes', []);

        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function actionFromRouteAndMethod(string $routeName, string $method): string
    {
        $method = strtoupper($method);

        if (in_array($method, ['GET', 'HEAD'], true)) {
            return 'view';
        }

        if ($method === 'DELETE') {
            return 'delete';
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return 'update';
        }

        if ($method === 'POST') {
            $createSuffixes = [
                '.store',
                '.add',
                '.checkout',
                '.payments.store',
                '.provision-owner',
            ];

            foreach ($createSuffixes as $suffix) {
                if (str_ends_with($routeName, $suffix)) {
                    return 'create';
                }
            }

            return 'update';
        }

        return 'view';
    }

    private static function routeRequirements(): array
    {
        return [
            'dashboard' => ['module' => 'dashboard', 'action' => 'view'],
            'admin.users.' => ['module' => 'users', 'action' => 'view'],
            'admin.user-access.' => ['module' => 'users', 'action' => 'view'],
            'admin.support-access.' => ['module' => 'support_access', 'action' => 'view'],
            'admin.franchise-registrations.' => ['module' => 'franchise_registrations', 'action' => 'view'],
            'admin.franchisees.' => ['module' => 'franchisees', 'action' => 'view'],
            'admin.franchises.' => ['module' => 'franchisees', 'action' => 'view'],
            'admin.products.' => ['module' => 'products', 'action' => 'view'],
            'admin.hsn-masters.' => ['module' => 'hsn_masters', 'action' => 'view'],
            'admin.salt-masters.' => ['module' => 'salt_masters', 'action' => 'view'],
            'admin.categories.' => ['module' => 'categories', 'action' => 'view'],
            'admin.companies.' => ['module' => 'companies', 'action' => 'view'],
            'admin.rack-layout.' => ['module' => 'rack_layout', 'action' => 'view'],
            'admin.suppliers.' => ['module' => 'suppliers', 'action' => 'view'],
            'admin.purchase-invoices.' => ['module' => 'purchase_invoices', 'action' => 'view'],
            'admin.purchase-returns.' => ['module' => 'purchase_returns', 'action' => 'view'],
            'admin.stock.adjust' => ['module' => 'stock_adjustment', 'action' => 'view'],
            'admin.dist-orders.' => ['module' => 'dist_orders', 'action' => 'view'],
            'b2b.cart.' => ['module' => 'b2b_cart', 'action' => 'view'],
            'pos.' => ['module' => 'pos', 'action' => 'view'],
            'customers.' => ['module' => 'customers', 'action' => 'view'],
            'franchise.staff.' => ['module' => 'franchise_staff', 'action' => 'view'],
            'tickets.' => ['module' => 'tickets', 'action' => 'view'],
            'meetings.' => ['module' => 'meetings', 'action' => 'view'],
            'shop-visits.' => ['module' => 'shop_visits', 'action' => 'view'],
            'ledger.' => ['module' => 'ledger', 'action' => 'view'],
            'expenses.' => ['module' => 'expenses', 'action' => 'view'],
            'reports.stock.' => ['module' => 'reports_stock', 'action' => 'view'],
            'reports.sales.' => ['module' => 'reports_sales', 'action' => 'view'],
            'reports.gst.' => ['module' => 'reports_gst', 'action' => 'view'],
            'reports.finance.' => ['module' => 'reports_finance', 'action' => 'view'],
            'reports.bi.' => ['module' => 'reports_bi', 'action' => 'view'],
            'reports.commissions' => ['module' => 'reports_commissions', 'action' => 'view'],
        ];
    }
}
