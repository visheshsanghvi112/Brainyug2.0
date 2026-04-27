
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Franchisee Purchase Items — line items in outside purchases
     */
    public function up(): void
    {
        Schema::create('franchisee_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_purchase_id')->constrained('franchisee_purchases')->restrictOnDelete();
            
            // Product Details
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('batch_no', 50);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date');
            
            // Quantities
            $table->decimal('qty', 12, 2);
            $table->decimal('free_qty', 12, 2)->default(0);
            $table->string('unit', 10)->default('pcs');
            
            // Pricing
            $table->decimal('mrp', 12, 2);
            $table->decimal('rate', 12, 2);
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            
            // Tax (from HSN at approval time)
            $table->decimal('gst_percent', 8, 2);
            $table->decimal('gst_amount', 12, 2);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            
            // Link to HSN for audit
            $table->foreignId('hsn_id')->nullable()->constrained('hsn_masters')->nullOnDelete();
            
            $table->timestamps();
            $table->index(['franchisee_purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchisee_purchase_items');
    }
};
