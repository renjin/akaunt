<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Service Tax 8%"
            $table->string('tax_type'); // sales | service | exempt | zero | not_applicable
            $table->decimal('rate', 5, 2)->default(0); // percent
            // Where collected output tax posts. Single-stage SST: no input-credit account exists.
            $table->foreignId('sst_payable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('myinvois_tax_type_code', 3)->nullable(); // LHDN tax type code
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
