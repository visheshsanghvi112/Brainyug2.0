<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces tbl_return_sale
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no')->unique();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->comment('May be null if original bill missing');
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            
            $table->date('return_date');
            $table->string('reason')->nullable();
            $table->decimal('total_refund_amount', 12, 2);
            
            // Refund method
            $table->string('refund_mode')->default('cash'); // cash, bank, adjust_in_wallet

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
