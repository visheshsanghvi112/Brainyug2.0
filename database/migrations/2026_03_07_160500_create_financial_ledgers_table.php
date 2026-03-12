<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledgers', function (Blueprint $table) {
            $table->id();
            
            // To track whose ledger this is (HO, specific franchisee, or supplier)
            $table->string('ledgerable_type'); 
            $table->unsignedBigInteger('ledgerable_id');
            
            // Transaction identification
            $table->date('transaction_date');
            $table->string('transaction_type')->comment('SALE, PURCHASE, PAYMENT_RECEIVED, PAYMENT_MADE, COMMISSION');
            $table->string('voucher_no')->unique()->nullable();
            
            // References back to the source model
            $table->nullableMorphs('reference'); 
            
            // Amounts
            $table->decimal('debit', 14, 2)->default(0); // Money owed / out
            $table->decimal('credit', 14, 2)->default(0); // Money received / in
            $table->decimal('running_balance', 14, 2); // Calculated at insertion

            $table->string('payment_mode')->nullable()->comment('CASH, BANK, NEFT, UPI, Adjustment');
            $table->string('reference_no')->nullable()->comment('Cheque/NEFT No');
            $table->text('narration')->nullable();

            $table->timestamps();
            
            $table->index(['ledgerable_type', 'ledgerable_id']);
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledgers');
    }
};
