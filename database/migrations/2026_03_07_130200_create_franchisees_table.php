<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Franchisees — the medical franchise shops.
     * Replaces legacy tbl_franchisee (60+ columns).
     *
     * Lifecycle: registered → pending → approved/rejected → active → deactivated
     */
    public function up(): void
    {
        Schema::create('franchisees', function (Blueprint $table) {
            $table->id();

            // ─── Shop Identity ───
            $table->string('shop_code', 20)->nullable()->unique();
            $table->string('shop_name');
            $table->enum('shop_type', ['franchise', 'distributor', 'sub_distributor'])->default('franchise');

            // ─── Owner Details ───
            $table->string('owner_name');
            $table->enum('owner_title', ['Mr', 'Mrs', 'Ms', 'Dr'])->default('Mr');
            $table->string('partner_name')->nullable();
            $table->enum('partner_title', ['Mr', 'Mrs', 'Ms', 'Dr'])->nullable();
            $table->date('owner_dob')->nullable();
            $table->unsignedTinyInteger('owner_age')->nullable();
            $table->string('education')->nullable();
            $table->string('occupation')->nullable();

            // ─── Contact ───
            $table->string('email')->nullable();
            $table->string('mobile', 15);
            $table->string('whatsapp', 15)->nullable();
            $table->string('alternate_phone', 15)->nullable();

            // ─── Address (Shop) ───
            $table->text('address')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('other_city')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // ─── Residence ───
            $table->text('residence_address')->nullable();
            $table->string('residence_from')->nullable();
            $table->string('distance_from_shop')->nullable();

            // ─── Legal & Compliance ───
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 12)->nullable();
            $table->string('dl_number_20b')->nullable();  // Drug License
            $table->string('dl_number_21b')->nullable();
            $table->string('dl_number_third')->nullable();
            $table->string('fssai_number')->nullable();

            // ─── Financial ───
            $table->string('bank_name')->nullable();
            $table->string('bank_account_holder')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc', 15)->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('utr_number')->nullable();
            $table->date('transaction_date')->nullable();
            $table->decimal('investment_amount', 12, 2)->nullable();
            $table->boolean('ready_to_invest')->default(false);

            // ─── Documents (stored as JSON paths) ───
            $table->json('documents')->nullable(); // {address_proof: "path", id_proof: "path", shop_photo: "path", ...}

            // ─── Approval Workflow ───
            $table->enum('status', [
                'enquiry',    // Initial enquiry (legacy franch_status=3)
                'registered', // Full form submitted (legacy franch_status=0)
                'approved',   // Admin approved
                'rejected',   // Admin rejected
                'active',     // Shop activated (legacy franch_status_menu=1)
                'suspended',  // Temporarily deactivated
                'banned'      // Permanently banned
            ])->default('registered');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // ─── Hierarchy Link ───
            $table->foreignId('district_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('zone_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('state_head_id')->nullable()->constrained('users')->nullOnDelete();

            // ─── Timestamps & Soft Delete ───
            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ───
            $table->index('status');
            $table->index('state_id');
            $table->index('district_id');
            $table->index('mobile');
        });

        // Now add the FK constraint on users.franchisee_id
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('franchisee_id')->references('id')->on('franchisees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['franchisee_id']);
        });

        Schema::dropIfExists('franchisees');
    }
};
