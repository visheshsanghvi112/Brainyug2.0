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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('company_id')->constrained('company_masters');
            $table->foreignId('category_id')->constrained('item_categories');
            $table->foreignId('salt_id')->constrained('salt_masters');
            $table->foreignId('hsn_id')->constrained('hsn_masters');
            $table->foreignId('box_size_id')->constrained('box_sizes')->nullable();
            
            // Core Identity
            $table->string('product_name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->index();
            $table->string('unit_sms_code')->nullable(); // Legacy tracking
            
            // Modern Pricing Tiers (2026 Standards)
            $table->decimal('mrp', 12, 2)->comment('Maximum Retail Price');
            $table->decimal('ptr', 12, 2)->comment('Price to Retailer');
            $table->decimal('pts', 12, 2)->comment('Price to Stockist');
            $table->decimal('cost', 12, 2)->default(0)->comment('Average Landing Cost');
            
            // Legacy Pricing Tiers (Rate A/B/C mapping)
            $table->decimal('rate_a', 12, 2)->nullable();
            $table->decimal('rate_b', 12, 2)->nullable();
            $table->decimal('rate_c', 12, 2)->nullable();
            
            // Packing & Conversion
            $table->string('packing_desc')->nullable(); // e.g. 10x10, 1x15
            $table->integer('conversion_factor')->default(1); // How many tablets in a box
            $table->boolean('is_loose_sellable')->default(false);
            
            // Inventory Rules
            $table->integer('min_stock_level')->default(0);
            $table->integer('max_stock_level')->default(0);
            $table->integer('reorder_quantity')->default(0);
            
            // Search & SEO (Vibe)
            $table->string('fast_search_index')->nullable();
            $table->json('images')->nullable(); // To store front/back/left/right legacy image paths
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_banned')->default(false);
            
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
