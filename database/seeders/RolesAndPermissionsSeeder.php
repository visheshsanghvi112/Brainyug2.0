<?php

namespace Database\Seeders;

use App\Support\ErpModuleAccess;
use App\Support\ErpRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // ═══ PERMISSIONS ═══
        $permissions = [
            'manage users', 'manage roles',
            'manage products', 'view products',
            'manage franchisees', 'view franchisees', 'approve franchisees', 'activate franchisees',
            'manage inventory', 'view inventory', 'create purchase challan', 'manage stock',
            'manage orders', 'place orders', 'approve orders', 'dispatch orders', 'view orders',
            'manage sales', 'create sale', 'view sales', 'process returns',
            'manage finance', 'view finance', 'manage commissions', 'process payments',
            'view reports', 'export reports', 'view gst reports',
            'manage settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach (ErpModuleAccess::allPermissionNames() as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $modulePermissionsByRole = [
            'Super Admin' => ErpModuleAccess::allPermissionNames(),
            'Admin' => $this->modulePermissions([
                'dashboard',
                'users',
                'support_access',
                'franchise_registrations',
                'franchisees',
                'products',
                'hsn_masters',
                'salt_masters',
                'categories',
                'companies',
                'rack_layout',
                'suppliers',
                'purchase_invoices',
                'purchase_returns',
                'stock_adjustment',
                'dist_orders',
                'ledger',
                'expenses',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_gst',
                'reports_finance',
                'reports_bi',
                'reports_commissions',
            ]),
            'State Head' => $this->modulePermissions([
                'dashboard',
                'franchise_registrations',
                'franchisees',
                'products',
                'dist_orders',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_bi',
                'reports_commissions',
            ], ['view', 'update']),
            'Regional Head' => $this->modulePermissions([
                'dashboard',
                'franchise_registrations',
                'franchisees',
                'products',
                'dist_orders',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_bi',
                'reports_commissions',
            ], ['view', 'update']),
            'Zonal Head' => $this->modulePermissions([
                'dashboard',
                'franchise_registrations',
                'franchisees',
                'products',
                'dist_orders',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_bi',
                'reports_commissions',
            ], ['view', 'update']),
            'District Head' => $this->modulePermissions([
                'dashboard',
                'franchise_registrations',
                'franchisees',
                'products',
                'dist_orders',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_bi',
                'reports_commissions',
            ], ['view', 'update']),
            'Franchisee' => $this->modulePermissions([
                'dashboard',
                'b2b_cart',
                'dist_orders',
                'pos',
                'sales_returns',
                'customers',
                'franchise_staff',
                'expenses',
                'tickets',
                'meetings',
                'shop_visits',
                'reports_stock',
                'reports_sales',
                'reports_commissions',
            ]),
            'Distributer' => $this->modulePermissions([
                'dashboard',
                'products',
                'suppliers',
                'purchase_invoices',
                'purchase_returns',
                'stock_adjustment',
                'dist_orders',
                'tickets',
                'meetings',
                'reports_stock',
                'reports_finance',
                'reports_commissions',
            ]),
            'Account' => $this->modulePermissions([
                'dashboard',
                'ledger',
                'expenses',
                'tickets',
                'meetings',
                'reports_gst',
                'reports_finance',
                'reports_commissions',
            ], ['view', 'update']),
            'Sales Team' => $this->modulePermissions([
                'dashboard',
                'franchisees',
                'products',
                'dist_orders',
                'tickets',
                'meetings',
                'reports_sales',
                'reports_bi',
                'reports_commissions',
            ], ['view', 'update']),
        ];

        $rolePermissions = [
            'Super Admin' => Permission::all()->pluck('name')->all(),
            'Admin' => [
                'manage users', 'manage roles', 'manage products', 'view products',
                'manage franchisees', 'view franchisees', 'approve franchisees', 'activate franchisees',
                'manage inventory', 'view inventory', 'create purchase challan', 'manage stock',
                'manage orders', 'approve orders', 'dispatch orders', 'view orders',
                'view sales', 'view finance', 'view reports', 'export reports', 'view gst reports',
            ],
            'State Head' => ['view franchisees', 'view products', 'view inventory', 'view orders', 'view sales', 'view finance', 'view reports', 'export reports'],
            'Regional Head' => ['view franchisees', 'view products', 'view inventory', 'view orders', 'view sales', 'view reports'],
            'Zonal Head' => ['view franchisees', 'view products', 'view inventory', 'view orders', 'view sales', 'view reports'],
            'District Head' => ['view franchisees', 'view products', 'view inventory', 'view orders', 'approve orders', 'view sales', 'view reports'],
            'Franchisee' => ['view products', 'view inventory', 'manage stock', 'place orders', 'view orders', 'create sale', 'view sales', 'process returns', 'view finance', 'view reports'],
            'Distributer' => ['view products', 'manage inventory', 'manage orders', 'approve orders', 'dispatch orders', 'view orders', 'view finance', 'view reports', 'export reports'],
            'Account' => ['view finance', 'process payments', 'view reports', 'export reports', 'view gst reports'],
            'Sales Team' => ['view products', 'view franchisees', 'view orders', 'view sales'],
        ];

        foreach ($rolePermissions as $roleName => $permissionsForRole) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            if ($roleName === 'Super Admin') {
                $role->syncPermissions(Permission::all());
                continue;
            }

            $role->syncPermissions(array_values(array_unique(array_merge(
                $permissionsForRole,
                $modulePermissionsByRole[$roleName] ?? []
            ))));
        }

        $compatibilityMap = [
            'Zone Head' => 'Zonal Head',
            'Sister Head' => 'Regional Head',
            'Distributor' => 'Distributer',
            'Payment Manager' => 'Account',
            'Sales Staff' => 'Sales Team',
            'Franchisee Staff' => 'Franchisee',
        ];

        foreach ($compatibilityMap as $legacyRole => $canonicalRole) {
            Role::firstOrCreate(['name' => $legacyRole])
                ->syncPermissions(array_values(array_unique(array_merge(
                    $rolePermissions[$canonicalRole],
                    $modulePermissionsByRole[$canonicalRole] ?? []
                ))));
        }

        foreach (array_diff(ErpRole::compatibilityRoles(), array_keys($compatibilityMap)) as $compatibilityRole) {
            Role::firstOrCreate(['name' => $compatibilityRole]);
        }
    }

    private function modulePermissions(array $modules, array $actions = ['view', 'create', 'update', 'delete']): array
    {
        $permissions = [];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                $permissions[] = ErpModuleAccess::permissionName($module, $action);
            }
        }

        return $permissions;
    }
}
