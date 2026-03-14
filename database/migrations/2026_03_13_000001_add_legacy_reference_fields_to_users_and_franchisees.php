<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('legacy_source')->nullable()->after('franchisee_id');
            $table->unsignedBigInteger('legacy_user_id')->nullable()->after('legacy_source');
            $table->integer('legacy_type')->nullable()->after('legacy_user_id');
            $table->string('legacy_username')->nullable()->after('legacy_type');

            $table->index(['legacy_source', 'legacy_user_id']);
        });

        Schema::table('franchisees', function (Blueprint $table) {
            $table->string('legacy_source')->nullable()->after('state_head_id');
            $table->unsignedBigInteger('legacy_franchise_id')->nullable()->after('legacy_source');

            $table->index(['legacy_source', 'legacy_franchise_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['legacy_source', 'legacy_user_id']);
            $table->dropColumn(['legacy_source', 'legacy_user_id', 'legacy_type', 'legacy_username']);
        });

        Schema::table('franchisees', function (Blueprint $table) {
            $table->dropIndex(['legacy_source', 'legacy_franchise_id']);
            $table->dropColumn(['legacy_source', 'legacy_franchise_id']);
        });
    }
};