<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add vendor credit terms + transporter info.
     * Legacy: purchase_challan had due_days, transporter, case_no columns.
     */
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_days')->default(0)->after('received_date')
                  ->comment('Vendor credit term in days; 0 = cash/immediate');
            $table->string('transporter', 100)->nullable()->after('due_days');
            $table->string('lr_number', 50)->nullable()->after('transporter')
                  ->comment('Lorry Receipt / GRN number from transporter');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_days', 'transporter', 'lr_number']);
        });
    }
};
