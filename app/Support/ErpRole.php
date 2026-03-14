<?php

namespace App\Support;

use App\Models\User;

final class ErpRole
{
    private const ROLE_ALIASES = [
        'Super Admin' => ['Super Admin'],
        'Admin' => ['Admin'],
        'State Head' => ['State Head'],
        'Regional Head' => ['Regional Head', 'Sister Head'],
        'Zonal Head' => ['Zonal Head', 'Zone Head'],
        'District Head' => ['District Head'],
        'Franchisee' => ['Franchisee', 'Franchisee Staff'],
        'Distributer' => ['Distributer', 'Distributor'],
        'Account' => ['Account', 'Payment Manager'],
        'Sales Team' => ['Sales Team', 'Sales Staff'],
        'Order' => ['Order'],
        'Warehouse' => ['Warehouse'],
        'Inward' => ['Inward'],
        'Outward' => ['Outward'],
        'Orderstaff' => ['Orderstaff'],
    ];

    private const PRIMARY_ROLE_ORDER = [
        'Super Admin',
        'Admin',
        'State Head',
        'Regional Head',
        'Zonal Head',
        'District Head',
        'Distributer',
        'Account',
        'Sales Team',
        'Franchisee',
        'Order',
        'Warehouse',
        'Inward',
        'Outward',
        'Orderstaff',
    ];

    public static function canonicalRoles(): array
    {
        return [
            'Super Admin',
            'Admin',
            'State Head',
            'Regional Head',
            'Zonal Head',
            'District Head',
            'Franchisee',
            'Distributer',
            'Account',
            'Sales Team',
        ];
    }

    public static function compatibilityRoles(): array
    {
        return [
            'Zone Head',
            'Sister Head',
            'Distributor',
            'Payment Manager',
            'Sales Staff',
            'Franchisee Staff',
            'Order',
            'Warehouse',
            'Inward',
            'Outward',
            'Orderstaff',
        ];
    }

    public static function aliasesFor(string $role): array
    {
        return self::ROLE_ALIASES[$role] ?? [$role];
    }

    public static function expand(array|string $roles): array
    {
        $roles = is_array($roles) ? $roles : [$roles];

        $expanded = [];

        foreach ($roles as $role) {
            foreach (self::aliasesFor($role) as $alias) {
                $expanded[] = $alias;
            }
        }

        return array_values(array_unique($expanded));
    }

    public static function canonicalize(string $role): string
    {
        foreach (self::ROLE_ALIASES as $canonical => $aliases) {
            if (in_array($role, $aliases, true)) {
                return $canonical;
            }
        }

        return $role;
    }

    public static function hasAny(User $user, array|string $roles): bool
    {
        return $user->hasAnyRole(self::expand($roles));
    }

    public static function primaryRoleFor(User $user): ?string
    {
        $assignedRoles = $user->getRoleNames()->all();

        foreach (self::PRIMARY_ROLE_ORDER as $role) {
            foreach (self::aliasesFor($role) as $alias) {
                if (in_array($alias, $assignedRoles, true)) {
                    return $role;
                }
            }
        }

        return $assignedRoles === [] ? null : self::canonicalize($assignedRoles[0]);
    }
}