<?php

namespace App\Services;

use App\Models\User;

class HomeRouteService
{
    public function routeName(User $user): string
    {
        if ($user->hasRole('Payment Manager')) {
            return 'ledger.index';
        }

        if ($user->hasRole('Distributor')) {
            return 'admin.dist-orders.index';
        }

        if ($user->hasRole('Sales Staff')) {
            return 'admin.franchisees.index';
        }

        return 'dashboard';
    }

    public function label(User $user): string
    {
        if ($user->hasRole('Payment Manager')) {
            return 'Finance Desk';
        }

        if ($user->hasRole('Distributor')) {
            return 'Order Desk';
        }

        if ($user->hasRole('Sales Staff')) {
            return 'Network Desk';
        }

        return 'Dashboard';
    }
}