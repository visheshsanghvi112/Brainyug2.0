<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('b2b_carts', 'status')) {
                $table->string('status')->default('draft')->after('user_id');
            }
            if (!Schema::hasColumn('b2b_carts', 'notes')) {
                $table->text('notes')->nullable()->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('b2b_carts', function (Blueprint $table) {
            if (Schema::hasColumn('b2b_carts', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('b2b_carts', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
