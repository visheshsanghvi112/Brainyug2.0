<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('bill_no')->unique(); // E.g., POS-FR12-20260307-001
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('user_id')->constrained('users')->comment('Cashier/Biller');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('doctor_id')->nullable()->constrained('doctors');
            
            $table->dateTime('date_time');
            
            // Financials
            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('total_discount_amount', 12, 2)->default(0);
            $table->decimal('total_tax_amount', 12, 2)->default(0); // For GST
            $table->decimal('other_charges', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            
            $table->string('status')->default('completed'); // completed, cancelled

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
