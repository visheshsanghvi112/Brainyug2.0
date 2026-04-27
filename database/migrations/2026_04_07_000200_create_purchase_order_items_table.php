<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_ordered')->default(0);
            $table->unsignedInteger('qty_received')->default(0); // Tracks GRN quantity
            $table->unsignedInteger('qty_rejected')->default(0);
            $table->unsignedInteger('qty_free')->default(0); // Free items

            // Pricing
            $table->decimal('mrp', 12, 2); // MRP
            $table->decimal('rate', 12, 2); // Purchase rate
            $table->decimal('line_amount', 14, 2)->default(0); // qty * rate
            $table->string('unit')->default('unit');

            // Tax
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0); // line_amount + gst

            // Shelf life
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_no')->nullable();

            // Discount
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            // Reference to line when converted to purchase invoice
            $table->foreignId('purchase_invoice_item_id')->nullable()->constrained('purchase_invoice_items')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
