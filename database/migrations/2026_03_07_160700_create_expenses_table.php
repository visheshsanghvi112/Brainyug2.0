<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->nullable()->constrained('franchisees')->comment('Null for HO expense');
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignId('user_id')->constrained('users')->comment('Who recorded it');
            
            $table->date('expense_date');
            $table->string('voucher_number')->index();
            $table->string('vendor_name')->nullable();
            
            $table->decimal('amount', 12, 2);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2); // amount + gst
            
            $table->string('payment_mode')->default('cash');
            $table->text('narration')->nullable();
            
            $table->string('status')->default('approved'); // pending, approved, rejected
            
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
