<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Franchisee Staff — employees hired by franchisees (cashiers, pharmacists).
     * Replaces legacy franchisee_users table.
     *
     * These users log in with their own credentials but are scoped to
     * their franchise's data (stock, sales, etc.).
     */
    public function up(): void
    {
        Schema::create('franchisee_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('designation')->nullable(); // e.g., Cashier, Pharmacist, Manager
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['franchisee_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchisee_staff');
    }
};
