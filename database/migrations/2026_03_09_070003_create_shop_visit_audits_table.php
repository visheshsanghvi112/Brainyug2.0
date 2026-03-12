<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_visit_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained('franchisees');
            $table->foreignId('auditor_id')->constrained('users'); // e.g., District Head / Zone Head
            $table->date('visit_date');
            $table->text('notes')->nullable();
            $table->integer('inspection_score')->nullable(); // Out of 100
            $table->json('photos')->nullable(); // Array of photo paths
            $table->json('checklist')->nullable(); // JSON of boolean checklist items
            $table->string('status')->default('completed'); // draft, completed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_visit_audits');
    }
};
