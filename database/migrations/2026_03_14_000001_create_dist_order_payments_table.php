<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dist_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dist_order_id')->constrained('dist_orders')->cascadeOnDelete();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('created_by')->constrained('users');
            $table->decimal('amount', 15, 2);
            $table->string('payment_mode', 30);
            $table->string('reference_no')->nullable();
            $table->date('payment_date');
            $table->text('narration')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('financial_ledger_id')->nullable()->constrained('financial_ledgers')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['dist_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dist_order_payments');
    }
};