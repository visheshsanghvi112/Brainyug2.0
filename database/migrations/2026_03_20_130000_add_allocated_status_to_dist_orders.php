<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The 'allocated' status was missing from the original dist_orders ENUM definition.
     * This migration adds it so the allocation step can properly persist.
     * MySQL ENUM modification uses a raw ALTER TABLE to replace the column definition.
     */
    public function up(): void
    {
        // MySQL: modify the enum to include 'allocated'.
        DB::statement("ALTER TABLE `dist_orders` MODIFY COLUMN `status` ENUM('pending','accepted','allocated','dispatched','delivered','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Remove 'allocated'; any existing rows with this value would need manual remediation.
        DB::statement("ALTER TABLE `dist_orders` MODIFY COLUMN `status` ENUM('pending','accepted','dispatched','delivered','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
