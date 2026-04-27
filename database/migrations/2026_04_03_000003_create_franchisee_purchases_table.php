
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Franchisee Outside Purchases — when franchisee buys from external vendor
     * instead of from HO B2B order.
     * 
     * New in v2.0 to track and audit proxy purchases approved by HO.
     * Legacy equivalent: purchase_challan (vendor side)
     */
    public function up(): void
    {
        Schema::create('franchisee_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            
            // Who & Where
            $table->foreignId('franchisee_id')->constrained('franchisees')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            
            // Workflow
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Dates
            $table->date('purchase_date');
            $table->date('received_date')->nullable();
            $table->string('financial_year', 10);
            
            // Context
            $table->enum('reason_code', ['normal', 'urgent', 'spot'])->default('normal');
            $table->text('notes')->nullable();
            
            // Totals
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('round_off', 10, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            
            $table->timestamps();
            $table->index(['franchisee_id', 'approval_status']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchisee_purchases');
    }
};
