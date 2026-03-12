<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces the legacy inline comission array inserts. Gives us immutable tracking of all structural payments.
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users'); // The parent franchisee/head receiving commission
            $table->foreignId('dist_order_id')->nullable()->constrained('dist_orders'); // Reference to the order generating it
            
            $table->enum('type', ['purchase_commission', 'sales_commission', 'joining_fee', 'other'])->default('purchase_commission');
            $table->enum('cr_dr', ['Cr', 'Dr']);
            
            $table->decimal('base_amount', 15, 2);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('gross_commission', 15, 2);
            
            $table->decimal('tds_percent', 5, 2)->default(0);
            $table->decimal('tds_amount', 15, 2)->default(0);
            
            $table->decimal('net_payable', 15, 2); // gross_commission - tds_amount
            
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, paid
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
