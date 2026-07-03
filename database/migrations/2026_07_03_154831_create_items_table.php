<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('service'); // goods | service
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->string('unit_of_measure')->nullable();
            $table->foreignId('income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->string('classification_code', 3)->nullable(); // LHDN e-Invoice classification
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
