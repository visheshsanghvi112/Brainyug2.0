<?php

namespace App\Console\Commands;

use App\Models\Franchisee;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Services\StockMonitoringService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStockExpiry extends Command
{
    protected $signature = 'stock:check-expiry {--days=30 : Alert window in days} {--limit=50 : Max rows to display in console output}';

    protected $description = 'Find positive-stock batches expiring within N days, create StockAlert records, and log summary.';

    public function __construct(
        private StockMonitoringService $stockMonitoringService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Running stock expiry check (window: {$days} days)...");
        
        // Use StockMonitoringService to detect and create alerts
        $alerts = $this->stockMonitoringService->detectExpiringBatches($days);

        if ($alerts->isEmpty()) {
            $message = sprintf('No expiring batches found in the next %d days.', $days);
            $this->info($message);
            Log::info('Stock expiry check: no expiring stock', [
                'days' => $days,
                'records' => 0,
            ]);
            return self::SUCCESS;
        }

        // Display results
        $this->warn(sprintf(
            'Created %d expiry alerts for batches expiring in next %d days.',
            $alerts->count(),
            $days
        ));

        $rows = $alerts->map(function ($alert) {
            return [
                'Location' => $alert->getLocationLabel(),
                'Product' => $alert->product->product_name,
                'Batch' => $alert->batch_no,
                'Expiry' => $alert->expiry_date,
                'Days Left' => now()->diffInDays($alert->expiry_date, false),
                'Stock' => (float) $alert->current_qty,
                'Level' => strtoupper($alert->alert_level),
            ];
        });

        $this->table(
            ['Location', 'Product', 'Batch', 'Expiry', 'Days Left', 'Stock', 'Level'],
            $rows->take($limit)->all()
        );

        if ($rows->count() > $limit) {
            $this->line(sprintf('Showing first %d rows out of %d.', $limit, $rows->count()));
        }

        Log::warning('Stock expiry alerts generated', [
            'days' => $days,
            'total_alerts' => $alerts->count(),
            'critical_count' => $alerts->where('alert_level', 'critical')->count(),
            'warning_count' => $alerts->where('alert_level', 'warning')->count(),
        ]);

        return self::SUCCESS;
    }
}
