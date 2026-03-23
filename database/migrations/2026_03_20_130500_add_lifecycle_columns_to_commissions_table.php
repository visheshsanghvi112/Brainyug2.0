<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->string('trigger_event', 50)->nullable()->after('status');
            $table->foreignId('reverses_commission_id')->nullable()->after('trigger_event')->constrained('commissions')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('reverses_commission_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');

            $table->index(['dist_order_id', 'status'], 'commissions_order_status_idx');
            $table->index(['user_id', 'status'], 'commissions_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex('commissions_order_status_idx');
            $table->dropIndex('commissions_user_status_idx');
            $table->dropConstrainedForeignId('reverses_commission_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['trigger_event', 'reversed_at', 'reversal_reason']);
        });
    }
};
