<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->char('currency', 3)->default('MYR');
            $table->decimal('fx_rate', 14, 6)->default(1);
            $table->decimal('debit_base', 16, 2)->default(0);  // MYR equivalents
            $table->decimal('credit_base', 16, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();
            $table->index('account_id');
        });

        // A line is a debit XOR a credit, never both, never negative.
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_debit_xor_credit
            CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
