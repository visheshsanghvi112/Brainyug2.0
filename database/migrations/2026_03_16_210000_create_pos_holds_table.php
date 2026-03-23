<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_holds', function (Blueprint $table) {
            $table->id();
            $table->string('hold_no', 64)->unique();
            $table->string('tab_code', 12)->index();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();

            $table->string('status', 20)->default('active')->index(); // active, completed, cancelled
            $table->boolean('is_locked')->default(false);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->json('customer_payload')->nullable();
            $table->json('doctor_payload')->nullable();
            $table->json('items_payload');
            $table->json('meta_payload')->nullable();

            $table->timestamp('held_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['franchisee_id', 'status']);
            $table->index(['franchisee_id', 'tab_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_holds');
    }
};
