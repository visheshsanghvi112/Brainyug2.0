<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_cart_items', function (Blueprint $table) {
            if (!Schema::hasColumn('b2b_cart_items', 'is_suggestion')) {
                $table->boolean('is_suggestion')->default(false)->after('qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('b2b_cart_items', function (Blueprint $table) {
            if (Schema::hasColumn('b2b_cart_items', 'is_suggestion')) {
                $table->dropColumn('is_suggestion');
            }
        });
    }
};
