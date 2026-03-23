<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_override_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('cashier_user_id')->constrained('users');
            $table->foreignId('supervisor_user_id')->constrained('users');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->string('action', 80);
            $table->string('request_id', 80);
            $table->string('token_hash', 128)->unique();
            $table->string('status', 20)->default('approved')->index();
            $table->string('reason', 160);
            $table->json('approval_snapshot')->nullable();
            $table->json('checkout_snapshot')->nullable();
            $table->timestamp('approved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['franchisee_id', 'cashier_user_id', 'approved_at'], 'poa_fr_cashier_approved_idx');
            $table->index(['franchisee_id', 'action', 'status'], 'poa_fr_action_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_override_audits');
    }
};
