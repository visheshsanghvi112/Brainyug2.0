<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_ledgers', function (Blueprint $table) {
            $table->foreignId('reversed_by')->nullable()->after('narration')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            $table->foreignId('reverses_financial_ledger_id')->nullable()->after('reversal_reason')->constrained('financial_ledgers')->nullOnDelete();
            $table->index('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('financial_ledgers', function (Blueprint $table) {
            $table->dropIndex(['reversed_at']);
            $table->dropConstrainedForeignId('reverses_financial_ledger_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
