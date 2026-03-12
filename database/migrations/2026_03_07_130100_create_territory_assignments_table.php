<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Territory Assignments — replaces legacy CSV-stored district codes!
     *
     * Legacy had: users.districtcode = "12,15,23" queried with FIND_IN_SET()
     * New system: proper pivot table with FK constraints.
     *
     * A State Head gets rows with territory_type='state'
     * A Zone Head gets rows with territory_type='district' (multiple districts)
     * A District Head gets rows with territory_type='district' (single or few)
     */
    public function up(): void
    {
        Schema::create('territory_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('territory_type', ['state', 'district', 'city']);
            $table->unsignedBigInteger('territory_id'); // FK to states.id or districts.id or cities.id
            $table->timestamps();

            // A user can only be assigned to a specific territory once
            $table->unique(['user_id', 'territory_type', 'territory_id'], 'territory_user_unique');

            // Efficient lookup: "give me all users assigned to district 12"
            $table->index(['territory_type', 'territory_id'], 'territory_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_assignments');
    }
};
