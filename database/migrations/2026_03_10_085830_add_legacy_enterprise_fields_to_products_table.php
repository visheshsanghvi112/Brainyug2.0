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
        Schema::table('products', function (Blueprint $table) {
            // Extended Catalog & Search
            $table->string('product_code', 50)->nullable()->after('sku');
            $table->string('item_type')->nullable()->after('product_code');
            $table->string('color_item_type')->nullable()->after('item_type');
            $table->string('company_code')->nullable()->after('company_id');
            $table->string('unit')->nullable()->after('packing_desc');
            $table->string('secondary_unit')->nullable()->after('unit');
            $table->text('ap_remark')->nullable()->after('fast_search_index');

            // Shelf & Location Mapping
            $table->unsignedBigInteger('rack_section_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('rack_area_id')->nullable()->after('rack_section_id');

            // Advanced Tax / Compliance Tracking
            $table->decimal('local_tax', 8, 2)->nullable()->after('is_loose_sellable');
            $table->decimal('central_tax', 8, 2)->nullable()->after('local_tax');
            $table->decimal('sgst', 5, 2)->nullable()->after('central_tax');
            $table->decimal('cgst', 5, 2)->nullable()->after('sgst');
            $table->decimal('igst', 5, 2)->nullable()->after('cgst');
            $table->decimal('csr', 5, 2)->nullable()->comment('Corporate Social Responsibility Tax/Duty')->after('igst');

            // Deep Margin & Discount Schema
            $table->decimal('p_rate_discount', 8, 2)->nullable()->after('rate_c');
            $table->decimal('item_special_discount', 8, 2)->nullable()->after('p_rate_discount');
            $table->decimal('special_discount', 8, 2)->nullable()->after('item_special_discount');
            $table->decimal('quantity_discount', 8, 2)->nullable()->after('special_discount');
            $table->decimal('max_discount', 8, 2)->nullable()->after('quantity_discount');
            $table->decimal('min_margin_disc', 8, 2)->nullable()->after('max_discount');
            $table->decimal('general_discount', 8, 2)->nullable()->after('min_margin_disc');
            $table->string('free_schema')->nullable()->comment('e.g., Buy 10 Get 1')->after('general_discount');

            // Extended Inventory Triggers
            $table->integer('shelflife')->nullable()->comment('In months')->after('reorder_quantity');
            $table->integer('reorder_days')->nullable()->after('shelflife');

            // Visibility / Legacy Status Tracking
            $table->boolean('hide')->default(false)->after('is_active');
            $table->string('product_type')->nullable()->after('hide');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'product_code', 'item_type', 'color_item_type', 'company_code', 
                'unit', 'secondary_unit', 'ap_remark', 'rack_section_id', 'rack_area_id',
                'local_tax', 'central_tax', 'sgst', 'cgst', 'igst', 'csr',
                'p_rate_discount', 'item_special_discount', 'special_discount', 
                'quantity_discount', 'max_discount', 'min_margin_disc', 'general_discount', 'free_schema',
                'shelflife', 'reorder_days', 'hide', 'product_type'
            ]);
        });
    }
};
