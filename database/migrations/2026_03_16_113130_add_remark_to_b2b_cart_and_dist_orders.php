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
        Schema::table('b2b_cart_items', function (Blueprint $table) {
            $table->string('remark')->nullable()->after('total_amount');
        });

        Schema::table('dist_order_items', function (Blueprint $table) {
            $table->string('remark')->nullable()->after('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('b2b_cart_items', function (Blueprint $table) {
            $table->dropColumn('remark');
        });

        Schema::table('dist_order_items', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
