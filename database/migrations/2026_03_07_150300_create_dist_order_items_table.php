<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dist_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dist_order_id')->constrained('dist_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            
            // Batch is nullable because Franchisee doesn't pick it immediately; HO allocates it on acceptance.
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            
            // Quantities
            $table->decimal('request_qty', 10, 2);
            $table->decimal('approved_qty', 10, 2)->nullable();
            $table->decimal('free_qty', 10, 2)->default(0);
            
            // Pricing (snapped at order time)
            $table->decimal('mrp', 10, 2)->default(0);
            $table->decimal('rate', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            
            // Line totals (For approved constraints)
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            
            $table->decimal('commission_amount', 15, 2)->default(0); // Optional line-level commission base
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dist_order_items');
    }
};
