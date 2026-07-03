<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0); // excl. tax, after discount
            $table->string('classification_code', 3)->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->foreignId('income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
