<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_tds_applicable')->default(false)->after('gst_amount');
            $table->decimal('tds_percent', 5, 2)->default(0)->after('is_tds_applicable');
            $table->decimal('tds_amount', 12, 2)->default(0)->after('tds_percent');
            $table->decimal('net_amount', 12, 2)->default(0)->after('total_amount');

            $table->index(['expense_date', 'payment_mode']);
            $table->index('is_tds_applicable');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['expense_date', 'payment_mode']);
            $table->dropIndex(['is_tds_applicable']);
            $table->dropColumn(['is_tds_applicable', 'tds_percent', 'tds_amount', 'net_amount']);
        });
    }
};
