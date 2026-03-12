<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add hierarchy & profile fields to users table.
     * Replaces legacy: users.type, users.parent_id, users.franch_id,
     * users.statecode, users.districtcode, users.distributer_id, users.dealer_id
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Profile fields
            $table->string('phone', 15)->nullable()->after('email');
            $table->string('username')->nullable()->unique()->after('name');

            // Hierarchy: who created / manages this user
            $table->foreignId('parent_id')->nullable()->constrained('users')->nullOnDelete()->after('id');

            // Franchisee link (if user is a franchisee owner or staff)
            $table->foreignId('franchisee_id')->nullable()->after('parent_id');

            // Status
            $table->boolean('is_active')->default(true)->after('password');
            $table->softDeletes();

            // Index for common lookups
            $table->index('parent_id');
            $table->index('franchisee_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'phone', 'username', 'parent_id', 'franchisee_id',
                'is_active', 'deleted_at'
            ]);
        });
    }
};
