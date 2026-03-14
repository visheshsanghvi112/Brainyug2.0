<?php

namespace App\Support;

use App\Models\User;

final class DashboardViewProfile
{
    public const AUTO = 'auto';
    public const EXECUTIVE = 'executive';
    public const ADMIN = 'admin';
    public const FRANCHISEE = 'franchisee';
    public const DISTRIBUTER = 'distributer';
    public const ACCOUNT = 'account';
    public const SALES_TEAM = 'sales_team';

    public static function options(): array
    {
        return [
            self::AUTO => 'Auto (Role Based)',
            self::EXECUTIVE => 'Executive Dashboard',
            self::ADMIN => 'Admin/Territory Dashboard',
            self::FRANCHISEE => 'Franchisee Operations Dashboard',
            self::DISTRIBUTER => 'Distributer Order Desk',
            self::ACCOUNT => 'Finance/Account Dashboard',
            self::SALES_TEAM => 'Sales Team Dashboard',
        ];
    }

    public static function allowedValues(): array
    {
        return array_keys(self::options());
    }

    public static function assignedFor(User $user): string
    {
        $value = (string) data_get($user->preferences ?? [], 'dashboard.view', self::AUTO);

        return in_array($value, self::allowedValues(), true) ? $value : self::AUTO;
    }

    public static function runtimeFor(User $user): string
    {
        $assigned = self::assignedFor($user);

        if ($assigned !== self::AUTO) {
            return $assigned;
        }

        if ($user->isFranchisee()) {
            return self::FRANCHISEE;
        }

        if ($user->isDistributer()) {
            return self::DISTRIBUTER;
        }

        if ($user->isAccount()) {
            return self::ACCOUNT;
        }

        if ($user->isSalesTeam()) {
            return self::SALES_TEAM;
        }

        if ($user->isSuperAdmin()) {
            return self::EXECUTIVE;
        }

        return self::ADMIN;
    }
}
