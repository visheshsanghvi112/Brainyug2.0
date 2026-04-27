
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock Alerts — audit trail for stock threshold violations, expiry warnings, and variances
     * 
     * Triggered by:
     *  - Purchase approvals (drop below reorder_qty)
     *  - Outside purchase approvals
     *  - Dispatch completions
     *  - Daily scheduled expiry checks
     *  - Stock reconciliation variances
     */
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            
            // What triggered this alert
            $table->enum('alert_type', ['threshold', 'expiry', 'variance', 'min_stock', 'overstock'])->default('threshold');
            
            // Which product & location
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('franchisee_id')->nullable()->constrained('franchisees')->nullOnDelete();
            // null franchisee_id = HO alert
            
            // The data at alert time
            $table->decimal('current_qty', 12, 2);
            $table->decimal('threshold_qty', 12, 2);
            $table->string('batch_no', 50)->nullable(); // For expiry alerts
            $table->date('expiry_date')->nullable();
            
            // Alert priority
            $table->enum('alert_level', ['info', 'warning', 'critical'])->default('warning');
            
            // Lifecycle
            $table->timestamp('triggered_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('action_taken')->nullable();
            
            // Status
            $table->enum('status', ['pending', 'acknowledged', 'resolved', 'false_alarm'])->default('pending');
            
            // What triggered this (reference)
            $table->string('trigger_source', 50)->nullable(); // 'purchase_approved', 'dispatch_completed', 'scheduled_check'
            $table->string('reference_type', 50)->nullable();  // 'purchase_invoice', 'dist_order', 'franchisee_purchase'
            $table->unsignedBigInteger('reference_id')->nullable();
            
            $table->timestamps();
            $table->index(['product_id', 'franchisee_id']);
            $table->index(['alert_level', 'status']);
            $table->index(['triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
