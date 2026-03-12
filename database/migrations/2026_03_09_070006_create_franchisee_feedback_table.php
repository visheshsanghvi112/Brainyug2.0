<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchisee_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('user_id')->constrained('users');
            $table->string('category')->default('general'); // support, product, technical
            $table->integer('rating')->nullable(); // 1 to 5 stars
            $table->text('comments');
            $table->string('status')->default('received'); // received, reviewed, actioned
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchisee_feedback');
    }
};
