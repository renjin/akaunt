<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('bill_number')->nullable(); // vendor's invoice number
            $table->string('status')->default('draft'); // draft | approved | partial | paid | void
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('MYR');
            $table->decimal('fx_rate', 14, 6)->default(1);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0); // SST paid — folded into expense on posting
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
    }
};
