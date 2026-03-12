<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atomic bill number counter per franchisee per day.
     * Prevents duplicate bill numbers under concurrent POS sessions.
     * Row is locked with SELECT FOR UPDATE before incrementing.
     */
    public function up(): void
    {
        Schema::create('bill_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('franchisee_id');
            $table->date('counter_date');
            $table->unsignedInteger('last_counter')->default(0);
            $table->timestamps();

            $table->unique(['franchisee_id', 'counter_date']);
            $table->index('counter_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_counters');
    }
};
