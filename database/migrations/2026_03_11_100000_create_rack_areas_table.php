<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rack areas are sub-locations within a rack section.
     * e.g. Section "A" → Area "A1", "A2", "A3"
     * Products are mapped to a section + area for physical warehouse location.
     */
    public function up(): void
    {
        Schema::create('rack_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rack_section_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['rack_section_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_areas');
    }
};
