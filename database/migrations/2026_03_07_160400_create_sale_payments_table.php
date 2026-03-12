<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            
            // Mode: cash, bank (UPI/Card), credit, or mixed modes like cash_bank, cash_credit
            $table->string('payment_mode');
            
            $table->decimal('cash_amount', 12, 2)->default(0);
            $table->decimal('bank_amount', 12, 2)->default(0);
            $table->decimal('credit_amount', 12, 2)->default(0);
            
            $table->string('transaction_no')->nullable()->comment('For UPI/Card payments');
            $table->string('wallet_type')->nullable(); // GPay, PhonePe, Paytm, etc.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
