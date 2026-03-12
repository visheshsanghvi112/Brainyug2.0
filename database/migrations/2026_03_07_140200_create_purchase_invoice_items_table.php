<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase Invoice Line Items — batch-level product entries.
     * Legacy: purchase_challan_product
     *
     * Each row = one product + batch received from supplier.
     */
    public function up(): void
    {
        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Batch details
            $table->string('batch_no', 50);
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();

            // Quantities
            $table->decimal('qty', 10, 2);
            $table->decimal('free_qty', 10, 2)->default(0);
            $table->string('unit', 20)->default('pcs'); // pcs, box, strip, etc.

            // Pricing
            $table->decimal('mrp', 10, 2);
            $table->decimal('rate', 10, 2); // purchase rate per unit
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);

            // GST
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->foreignId('hsn_id')->nullable()->constrained('hsn_masters')->nullOnDelete();

            // Computed
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['product_id', 'batch_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};
