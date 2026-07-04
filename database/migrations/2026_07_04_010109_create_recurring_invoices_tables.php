<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('frequency'); // weekly | monthly | quarterly | yearly
            $table->unsignedSmallInteger('due_days')->default(30);
            $table->date('next_run_date');
            $table->date('last_run_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->char('currency', 3)->default('MYR');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'active', 'next_run_date']);
        });

        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('classification_code', 3)->nullable();
            $table->foreignId('income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_lines');
        Schema::dropIfExists('recurring_invoices');
    }
};
