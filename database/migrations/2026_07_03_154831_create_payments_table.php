<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('payment_type'); // received | made
            $table->string('method')->default('bank_transfer'); // fpx | duitnow | bank_transfer | cash | card | cheque
            $table->date('payment_date');
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('MYR');
            $table->decimal('fx_rate', 14, 6)->default(1);
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
