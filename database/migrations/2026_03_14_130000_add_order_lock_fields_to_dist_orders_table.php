<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dist_orders', function (Blueprint $table) {
            $table->foreignId('locked_by')->nullable()->after('dispatched_by')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');

            $table->index(['locked_by', 'locked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dist_orders', function (Blueprint $table) {
            $table->dropIndex(['locked_by', 'locked_at']);
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn('locked_at');
        });
    }
};
