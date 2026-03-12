<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase Invoices — HO receiving stock from suppliers.
     * Legacy: purchase_challan (header) + purchase_challan_product (line items)
     *
     * Flow: Supplier ships goods → HO receives → creates purchase invoice
     *       → on approval, inventory_ledger PURCHASE entries created
     */
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50);
            $table->string('supplier_invoice_no', 50)->nullable(); // supplier's own invoice #
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('received_date')->nullable();

            // Financial Year tracking (legacy: duplicate check per FY)
            $table->string('financial_year', 10); // e.g., "2025-26"

            // Amounts
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            // Tax type
            $table->enum('tax_type', ['intra_state', 'inter_state'])->default('intra_state');
            // intra = SGST+CGST, inter = IGST

            // Status
            $table->enum('status', ['draft', 'approved', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate supplier invoice per FY
            $table->unique(['supplier_id', 'supplier_invoice_no', 'financial_year'], 'unique_supplier_invoice_fy');
            $table->index('status');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
