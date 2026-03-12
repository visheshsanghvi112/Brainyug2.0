<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('b2b_cart_id')->constrained('b2b_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 10, 2);
            $table->decimal('free_qty', 10, 2)->default(0);
            $table->decimal('rate', 10, 2);
            $table->decimal('total_amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_cart_items');
    }
};
