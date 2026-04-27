<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_source_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['purchase_return_id', 'purchase_invoice_id'], 'pr_source_invoice_unique');
            $table->index('purchase_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_source_invoices');
    }
};
