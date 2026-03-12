<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('batch_no');
            
            $table->integer('qty');
            $table->decimal('rate', 10, 2);
            $table->decimal('gst_percent', 5, 2);
            $table->decimal('refund_amount', 12, 2); // includes GST

            $table->string('status')->default('restocked')->comment('restocked, damaged');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
    }
};
