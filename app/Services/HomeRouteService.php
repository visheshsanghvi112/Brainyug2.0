<?php

namespace App\Services;

use App\Models\User;
use App\Support\ErpModuleAccess;
use Illuminate\Support\Facades\Route;

class HomeRouteService
{
    public function routeName(User $user): string
    {
        $override = (string) data_get($user->preferences ?? [], 'dashboard.landing_route', '');
        if ($override !== '' && Route::has($override) && ErpModuleAccess::canAccessRoute($user, $override)) {
            return $override;
        }

        if ($user->isAccount() && ErpModuleAccess::can($user, 'ledger', 'view')) {
            return 'ledger.index';
        }

        if ($user->isDistributer() && ErpModuleAccess::can($user, 'dist_orders', 'view')) {
            return 'admin.dist-orders.index';
        }

        if ($user->isSalesTeam() && ErpModuleAccess::can($user, 'franchisees', 'view')) {
            return 'admin.franchisees.index';
        }

        return ErpModuleAccess::can($user, 'dashboard', 'view') ? 'dashboard' : 'profile.edit';
    }

    public function label(User $user): string
    {
        if ($user->isAccount()) {
            return 'Finance Desk';
        }

        if ($user->isDistributer()) {
            return 'Order Desk';
        }

        if ($user->isSalesTeam()) {
            return 'Network Desk';
        }

        return 'Dashboard';
    }
}