<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE sales_invoice_items MODIFY qty DECIMAL(12,4) NOT NULL');
        DB::statement('ALTER TABLE sales_return_items MODIFY qty DECIMAL(12,4) NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE sales_return_items MODIFY qty INT NOT NULL');
        DB::statement('ALTER TABLE sales_invoice_items MODIFY qty INT NOT NULL');
    }
};
