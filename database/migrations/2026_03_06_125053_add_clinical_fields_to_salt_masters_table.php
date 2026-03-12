<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('salt_masters', function (Blueprint $table) {
            $table->text('indication')->nullable()->after('name');
            $table->text('dosage')->nullable()->after('indication');
            $table->text('side_effects')->nullable()->after('dosage');
            $table->text('special_precaution')->nullable()->after('side_effects');
            $table->text('drug_interaction')->nullable()->after('special_precaution');
            $table->boolean('is_narcotic')->default(false)->after('drug_interaction');
            $table->boolean('schedule_h')->default(false)->after('is_narcotic');
            $table->boolean('schedule_h1')->default(false)->after('schedule_h');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salt_masters', function (Blueprint $table) {
            $table->dropColumn([
                'indication', 'dosage', 'side_effects', 
                'special_precaution', 'drug_interaction', 
                'is_narcotic', 'schedule_h', 'schedule_h1'
            ]);
        });
    }
};
