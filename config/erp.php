<?php

return [
    'module_access' => [
        // When true, named protected routes without explicit module mapping are denied.
        // Keep false during rollout; enable true after mapping audits are complete.
        'strict_unmapped' => env('ERP_MODULE_STRICT_UNMAPPED', false),

        // Named route prefixes that should always be covered by module authorization.
        'protected_route_prefixes' => [
            'admin.',
            'b2b.',
            'pos.',
            'customers.',
            'franchise.staff.',
            'tickets.',
            'meetings.',
            'shop-visits.',
            'ledger.',
            'expenses.',
            'reports.',
        ],
    ],
];
