<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_ledger_id')->constrained()->restrictOnDelete();
            $table->date('allocation_date');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->index(['supplier_id', 'purchase_invoice_id'], 'supplier_payment_allocations_supplier_invoice_idx');
            $table->index(['financial_ledger_id'], 'supplier_payment_allocations_ledger_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
