<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->string('customer_ref')->nullable()->after('estimate_number');
        });

        Schema::table('estimate_lines', function (Blueprint $table) {
            // Multi-tax per line. tax_code_id stays as the first selected code for back-compat.
            $table->json('tax_code_ids')->nullable()->after('tax_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->dropColumn('tax_code_ids');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('customer_ref');
        });
    }
};
