<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seeds all 9 legacy-referenced roles to ensure 1:1 business logic parity.
     * Legacy IDs: 1=SuperAdmin, 2=StateHead, 3=ZoneHead, 4=DistrictHead, 5=Franchisee, 6=Distributor, 8=SisterHead, 9=PaymentManager, 10=SalesStaff
     */
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

        // ═══ ROLES (ROBUST 1:1 MAPPING) ═══

        // 1. Super Admin
        Role::firstOrCreate(['name' => 'Super Admin'])->givePermissionTo(Permission::all());

        // 2. State Head
        Role::firstOrCreate(['name' => 'State Head'])->syncPermissions([
            'view franchisees', 'view products', 'view inventory',
            'view orders', 'view sales', 'view finance',
            'view reports', 'export reports',
        ]);

        // 3. Zone Head
        Role::firstOrCreate(['name' => 'Zone Head'])->syncPermissions([
            'view franchisees', 'view products', 'view inventory',
            'view orders', 'view sales', 'view reports',
        ]);

        // 4. District Head
        Role::firstOrCreate(['name' => 'District Head'])->syncPermissions([
            'view franchisees', 'view products', 'view inventory',
            'view orders', 'approve orders', 'view sales', 'view reports',
        ]);

        // 5. Franchisee
        Role::firstOrCreate(['name' => 'Franchisee'])->syncPermissions([
            'view products', 'view inventory', 'manage stock',
            'place orders', 'view orders',
            'create sale', 'view sales', 'process returns',
            'view finance', 'view reports',
        ]);

        // 6. Distributor
        Role::firstOrCreate(['name' => 'Distributor'])->syncPermissions([
            'view products', 'manage inventory',
            'manage orders', 'approve orders', 'dispatch orders', 'view orders',
            'view finance', 'view reports', 'export reports',
        ]);

        // 8. Sister Head (Specific subset of support/audit)
        Role::firstOrCreate(['name' => 'Sister Head'])->syncPermissions([
            'view franchisees', 'view products', 'view inventory',
            'view reports',
        ]);

        // 9. Payment Manager (Accounts / Payment Verification)
        Role::firstOrCreate(['name' => 'Payment Manager'])->syncPermissions([
            'view finance', 'process payments', 'view reports',
        ]);

        // 10. Sales Staff (HO / Field sales)
        Role::firstOrCreate(['name' => 'Sales Staff'])->syncPermissions([
            'view products', 'view franchisees', 'view orders', 'view sales',
        ]);

        // Extra: Franchisee Staff (Local store level)
        Role::firstOrCreate(['name' => 'Franchisee Staff'])->syncPermissions([
            'view products', 'view inventory',
            'create sale', 'view sales', 'process returns',
        ]);
    }
}
