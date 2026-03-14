<?php

namespace App\Support;

final class DashboardAccessPolicy
{
    public const SECTION_TREND = 'trend';
    public const SECTION_PIPELINE = 'pipeline';
    public const SECTION_LEADERBOARD = 'leaderboard';
    public const SECTION_ALERTS = 'alerts';
    public const SECTION_FOCUS = 'focus';
    public const SECTION_ACTIONS = 'actions';
    public const SECTION_WORKFLOWS = 'workflows';
    public const SECTION_STATS = 'stats';

    public static function sectionOptions(): array
    {
        return [
            self::SECTION_TREND => 'Trend Chart',
            self::SECTION_PIPELINE => 'Pipeline Matrix',
            self::SECTION_LEADERBOARD => 'Leaderboard',
            self::SECTION_ALERTS => 'Alerts',
            self::SECTION_FOCUS => 'Focus Strip',
            self::SECTION_ACTIONS => 'Quick Actions',
            self::SECTION_WORKFLOWS => 'Workflow Cards',
            self::SECTION_STATS => 'KPI Stat Cards',
        ];
    }

    public static function allSections(): array
    {
        return array_keys(self::sectionOptions());
    }

    public static function landingRouteOptions(): array
    {
        return [
            'dashboard' => 'Default Dashboard',
            'ledger.index' => 'Finance Desk (Ledger)',
            'admin.dist-orders.index' => 'Distribution Orders',
            'admin.franchisees.index' => 'Franchise Network',
            'admin.products.index' => 'Product Catalog',
            'admin.purchase-invoices.index' => 'Procurement Desk',
            'b2b.cart.index' => 'B2B Order Cart',
            'pos.index' => 'Retail POS',
            'customers.index' => 'Customer Directory',
            'expenses.index' => 'Expenses',
            'tickets.index' => 'Support Tickets',
        ];
    }

    public static function allowedLandingRoutes(): array
    {
        return array_keys(self::landingRouteOptions());
    }
}
