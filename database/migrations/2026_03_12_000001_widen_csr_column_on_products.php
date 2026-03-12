<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('csr', 8, 2)->nullable()->comment('Corporate Social Responsibility Tax/Duty')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('csr', 5, 2)->nullable()->comment('Corporate Social Responsibility Tax/Duty')->change();
        });
    }
};
