<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Event-Sourced Inventory Ledger — THE core table.
     * Replaces legacy: direct tbl_stock manipulation (no audit trail!)
     *
     * Every stock movement is an IMMUTABLE ledger entry.
     * Current stock = SUM(qty_in) - SUM(qty_out) for a given product+batch+location.
     *
     * This eliminates:
     *   - Direct stock manipulation bugs
     *   - Dual-database deduction (FMS + PharmaERP)
     *   - Missing audit trails
     *   - Race conditions on concurrent stock updates
     */
    public function up(): void
    {
        Schema::create('inventory_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 50);
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->decimal('mrp', 10, 2)->nullable();

            // Location: WHERE is this stock?
            // location_type: 'warehouse' (HO), 'franchisee', 'distributor'
            // location_id: franchisee.id or distributor user.id or 0 for HO warehouse
            $table->string('location_type', 20); // warehouse, franchisee, distributor
            $table->unsignedBigInteger('location_id')->default(0);

            // Transaction type: WHY did stock change?
            $table->enum('transaction_type', [
                'PURCHASE',     // HO received from supplier
                'SALE',         // Franchisee sold to customer (POS)
                'DISPATCH',     // HO dispatched to franchisee (B2B order)
                'RECEIVE',      // Franchisee received dispatched goods
                'RETURN_SALE',  // Customer returned to franchisee
                'RETURN_PURCHASE', // HO returned to supplier
                'ADJUSTMENT',   // Manual stock correction
                'TRANSFER',     // Stock transfer between locations
                'OPENING',      // Opening balance / migration
            ]);

            // Reference: WHICH document caused this?
            $table->string('reference_type', 50)->nullable(); // purchase_invoice, sales_invoice, dist_order, etc.
            $table->unsignedBigInteger('reference_id')->nullable();

            // Quantities (always positive, direction determined by type)
            $table->decimal('qty_in', 12, 2)->default(0);
            $table->decimal('qty_out', 12, 2)->default(0);

            // Rate at time of transaction (for FIFO/weighted avg costing)
            $table->decimal('rate', 10, 2)->nullable();

            // Who did it
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // ═══ INDEXES for common queries ═══
            // Stock lookup: "how much of product X, batch Y at location Z?"
            $table->index(['product_id', 'batch_no', 'location_type', 'location_id'], 'idx_stock_lookup');
            // Location stock: "what's everything at franchisee 42?"
            $table->index(['location_type', 'location_id'], 'idx_location_stock');
            // Expiry alerts
            $table->index('expiry_date', 'idx_expiry');
            // Audit trail for a document
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
            // Transaction type filter
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledgers');
    }
};
