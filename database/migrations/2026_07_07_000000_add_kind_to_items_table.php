<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('kind')->default('sales')->after('type'); // sales | purchase
        });

        // Backfill: income set -> sales; expense set AND income null -> purchase; else sales.
        DB::table('items')
            ->whereNull('income_account_id')
            ->whereNotNull('expense_account_id')
            ->update(['kind' => 'purchase']);

        DB::table('items')
            ->where(function ($q) {
                $q->whereNotNull('income_account_id')
                    ->orWhereNull('expense_account_id');
            })
            ->update(['kind' => 'sales']);

        // Enforce XOR on existing data.
        DB::table('items')->where('kind', 'sales')->update(['expense_account_id' => null]);
        DB::table('items')->where('kind', 'purchase')->update(['income_account_id' => null]);
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
