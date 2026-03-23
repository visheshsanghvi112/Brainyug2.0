<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dist_order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dist_order_id')->constrained('dist_orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['dist_order_id', 'created_at'], 'dist_order_status_logs_order_created_idx');
            $table->index(['to_status', 'created_at'], 'dist_order_status_logs_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dist_order_status_logs');
    }
};
