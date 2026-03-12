<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_masters', function (Blueprint $table) {
            $table->string('preference')->nullable()->after('dl_no');
            $table->string('dump_days')->nullable()->after('preference');
            $table->string('expiry_receive_upto')->nullable()->after('dump_days');
            $table->string('minimum_margin')->nullable()->after('expiry_receive_upto');
            $table->string('sales_tax')->nullable()->after('minimum_margin');
            $table->string('purchase_tax')->nullable()->after('sales_tax');
        });

        Schema::table('hsn_masters', function (Blueprint $table) {
            $table->string('hsn_name')->nullable()->after('id');
            $table->string('unit')->nullable()->after('igst_percent');
        });

        Schema::table('salt_masters', function (Blueprint $table) {
            $table->text('note')->nullable()->after('drug_interaction'); // Already has description, but note is in legacy
            $table->string('maximum_rate')->nullable()->after('schedule_h1');
            $table->string('continued')->nullable()->after('maximum_rate');
            $table->string('prohibited')->nullable()->after('continued');
            $table->unsignedBigInteger('legacy_category_id')->nullable()->after('prohibited');
            $table->unsignedBigInteger('legacy_sub_category_id')->nullable()->after('legacy_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_masters', function (Blueprint $table) {
            $table->dropColumn(['preference', 'dump_days', 'expiry_receive_upto', 'minimum_margin', 'sales_tax', 'purchase_tax']);
        });

        Schema::table('hsn_masters', function (Blueprint $table) {
            $table->dropColumn(['hsn_name', 'unit']);
        });

        Schema::table('salt_masters', function (Blueprint $table) {
            $table->dropColumn(['note', 'maximum_rate', 'continued', 'prohibited', 'legacy_category_id', 'legacy_sub_category_id']);
        });
    }
};
