<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('category_account_id')
                ->constrained('parties')->nullOnDelete();
            $table->string('receipt_path')->nullable()->after('party_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_id');
            $table->dropColumn('receipt_path');
        });
    }
};
