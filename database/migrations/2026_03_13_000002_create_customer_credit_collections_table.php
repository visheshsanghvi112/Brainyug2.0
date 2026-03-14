<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained('franchisees')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_mode', 30);
            $table->string('transaction_no', 100)->nullable();
            $table->string('wallet_type', 50)->nullable();
            $table->string('narration', 500)->nullable();
            $table->date('collected_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'franchisee_id']);
            $table->index(['sales_invoice_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_collections');
    }
};
