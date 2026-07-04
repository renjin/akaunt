<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('estimate_number');
            $table->string('status')->default('draft'); // draft | sent | accepted | expired | converted
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->char('currency', 3)->default('MYR');
            $table->decimal('fx_rate', 14, 6)->default(1);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'estimate_number']);
        });

        Schema::create('estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->string('classification_code', 3)->nullable();
            $table->foreignId('income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_lines');
        Schema::dropIfExists('estimates');
    }
};
