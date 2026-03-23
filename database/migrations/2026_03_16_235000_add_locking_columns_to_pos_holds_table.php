<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_holds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_holds', 'lock_owner_user_id')) {
                $table->foreignId('lock_owner_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('pos_holds', 'lock_expires_at')) {
                $table->timestamp('lock_expires_at')->nullable()->after('held_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_holds', function (Blueprint $table) {
            if (Schema::hasColumn('pos_holds', 'lock_owner_user_id')) {
                $table->dropConstrainedForeignId('lock_owner_user_id');
            }

            if (Schema::hasColumn('pos_holds', 'lock_expires_at')) {
                $table->dropColumn('lock_expires_at');
            }
        });
    }
};
