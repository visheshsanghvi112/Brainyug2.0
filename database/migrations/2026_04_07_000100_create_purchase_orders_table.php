<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase Orders — HO planning & sending procurement requests to suppliers.
     * Precursor to Purchase Invoices.
     *
     * Flow: HO creates PO → approves PO → sends to supplier → supplier ships
     *       → HO receives goods → creates purchase invoice from PO
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->date('required_date')->nullable(); // Required delivery date
            $table->date('expected_delivery_date')->nullable();

            // Financial Year tracking
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

            // Status: draft -> approved -> sent -> received -> invoiced -> cancelled
            $table->enum('status', ['draft', 'approved', 'sent', 'received', 'invoiced', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();

            // Logistics info
            $table->string('transporter')->nullable();
            $table->string('lr_number')->nullable(); // Loading/Receipt number
            $table->decimal('transport_cost', 10, 2)->nullable();

            // Reference to converted purchase invoice (when received)
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->json('quote_reference')->nullable(); // Store supplier quote details
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('order_date');
            $table->index('supplier_id');
            $table->index('financial_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
