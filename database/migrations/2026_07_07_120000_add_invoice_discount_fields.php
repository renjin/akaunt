<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Invoice-level (whole-invoice) discount, Wave-style: percent or fixed.
// discount_total already exists as the computed amount; these hold the input.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('discount_type')->default('fixed')->after('discount_total'); // 'fixed' | 'percent'
            $table->decimal('discount_value', 16, 2)->default(0)->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
