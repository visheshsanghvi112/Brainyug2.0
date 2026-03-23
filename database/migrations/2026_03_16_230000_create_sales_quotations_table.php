<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_no')->unique();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('doctor_id')->nullable()->constrained('doctors');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices');
            $table->dateTime('quotation_date');
            $table->string('status', 20)->default('active'); // active, converted, cancelled
            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('total_discount_amount', 12, 2)->default(0);
            $table->decimal('total_tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['franchisee_id', 'customer_id']);
            $table->index(['franchisee_id', 'status']);
        });

        Schema::create('sales_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_quotation_id')->constrained('sales_quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('batch_no')->nullable();
            $table->decimal('qty', 12, 4);
            $table->decimal('free_qty', 10, 2)->default(0);
            $table->decimal('mrp', 10, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['sales_quotation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_items');
        Schema::dropIfExists('sales_quotations');
    }
};
