<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            // Multi-tax per line (Wave-style). `tax_code_id` is kept populated with the
            // first selected code for backward compat (e-Invoice/reports).
            $table->json('tax_code_ids')->nullable()->after('tax_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('tax_code_ids');
        });
    }
};
