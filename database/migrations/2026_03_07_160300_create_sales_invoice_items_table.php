<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('batch_no');
            $table->date('exp_date')->nullable();
            
            $table->integer('qty')->comment('Units (tablets/bottles) sold');
            $table->decimal('mrp', 10, 2);
            $table->decimal('rate', 10, 2)->comment('Selling price before discount per unit');
            
            // Discount calculation at line level
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('gst_percent', 5, 2);
            $table->decimal('gst_amount', 10, 2);
            
            $table->decimal('total_amount', 12, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
    }
};
