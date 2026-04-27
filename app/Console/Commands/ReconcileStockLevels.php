<?php

namespace App\Console\Commands;

use App\Services\StockMonitoringService;
use Illuminate\Console\Command;

class ReconcileStockLevels extends Command
{
    protected $signature = 'stock:reconcile {--variance=5 : Variance threshold percent}';
    protected $description = 'Detect stock variances and create alerts for manual investigation';

    public function __construct(
        private StockMonitoringService $stockMonitoringService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $variance = (float) $this->option('variance');

        $this->info("Running stock reconciliation (variance > {$variance}%)...");

        $alerts = $this->stockMonitoringService->detectStockVariances($variance);

        if ($alerts->isEmpty()) {
            $this->info('No stock variances detected.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$alerts->count()} stock variances:");

        foreach ($alerts as $alert) {
            $location = $alert->getLocationLabel();
            $variance = $alert->action_taken ?? 'Unknown';
            $this->line("  ⚠ {$alert->product->product_name} at {$location} - {$variance}");
        }

        return Command::SUCCESS;
    }
}
