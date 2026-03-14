<?php

namespace App\Console\Commands;

use App\Models\Franchisee;
use App\Models\InventoryLedger;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStockExpiry extends Command
{
    protected $signature = 'stock:check-expiry {--days=30 : Alert window in days} {--limit=50 : Max rows to display in console output}';

    protected $description = 'Find positive-stock batches expiring within N days and log an expiry alert summary.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $today = Carbon::today();
        $threshold = Carbon::today()->addDays($days);

        $rows = InventoryLedger::query()
            ->selectRaw('location_type, location_id, product_id, batch_no, expiry_date, SUM(qty_in - qty_out) as stock')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $threshold)
            ->groupBy('location_type', 'location_id', 'product_id', 'batch_no', 'expiry_date')
            ->havingRaw('SUM(qty_in - qty_out) > 0')
            ->orderBy('expiry_date')
            ->get();

        if ($rows->isEmpty()) {
            $message = sprintf('No expiring stock found in the next %d days (%s to %s).', $days, $today->toDateString(), $threshold->toDateString());
            $this->info($message);
            Log::info('Stock expiry alert: no expiring stock', [
                'days' => $days,
                'window_start' => $today->toDateString(),
                'window_end' => $threshold->toDateString(),
                'records' => 0,
            ]);

            return self::SUCCESS;
        }

        $productNames = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->unique()->all())
            ->pluck('product_name', 'id');

        $franchiseeIds = $rows
            ->where('location_type', 'franchisee')
            ->pluck('location_id')
            ->unique()
            ->all();

        $franchiseeNames = Franchisee::query()
            ->whereIn('id', $franchiseeIds)
            ->pluck('shop_name', 'id');

        $reportRows = $rows->map(function ($row) use ($productNames, $franchiseeNames, $today) {
            $expiry = Carbon::parse($row->expiry_date);
            $location = match ($row->location_type) {
                'warehouse' => 'HO Warehouse',
                'franchisee' => $franchiseeNames[$row->location_id] ?? ('Franchisee #' . $row->location_id),
                default => ucfirst($row->location_type) . ' #' . $row->location_id,
            };

            return [
                'Location' => $location,
                'Product' => $productNames[$row->product_id] ?? ('Product #' . $row->product_id),
                'Batch' => $row->batch_no,
                'Expiry' => $expiry->toDateString(),
                'Days Left' => $today->diffInDays($expiry, false),
                'Stock' => (float) $row->stock,
            ];
        });

        $this->warn(sprintf(
            'Expiry alert: %d batch records expiring in next %d days (%s to %s).',
            $reportRows->count(),
            $days,
            $today->toDateString(),
            $threshold->toDateString()
        ));

        $this->table(
            ['Location', 'Product', 'Batch', 'Expiry', 'Days Left', 'Stock'],
            $reportRows->take($limit)->all()
        );

        if ($reportRows->count() > $limit) {
            $this->line(sprintf('Showing first %d rows out of %d.', $limit, $reportRows->count()));
        }

        Log::warning('Stock expiry alert generated', [
            'days' => $days,
            'window_start' => $today->toDateString(),
            'window_end' => $threshold->toDateString(),
            'records' => $reportRows->count(),
            'locations' => $rows->groupBy(fn ($r) => $r->location_type . ':' . $r->location_id)->count(),
            'total_stock' => round((float) $rows->sum('stock'), 2),
            'top_items' => $reportRows->take(10)->values()->all(),
        ]);

        return self::SUCCESS;
    }
}
