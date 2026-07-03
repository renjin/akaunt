<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('type');    // asset | liability | equity | income | expense
            $table->string('subtype'); // cash_bank, accounts_receivable, accounts_payable, sst_payable, retained_earnings, partner_capital, ...
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false); // protect A/R, A/P, SST payable, retained earnings
            $table->char('currency', 3)->default('MYR');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
