<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchisees', function (Blueprint $table) {
            $table->decimal('purchase_commission_percent', 5, 2)->default(0)->after('status');
            $table->decimal('sales_commission_percent', 5, 2)->default(0)->after('purchase_commission_percent');
            $table->decimal('tds_percent', 5, 2)->default(5)->after('sales_commission_percent');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_commissionable')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('franchisees', function (Blueprint $table) {
            $table->dropColumn(['purchase_commission_percent', 'sales_commission_percent', 'tds_percent']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_commissionable');
        });
    }
};
