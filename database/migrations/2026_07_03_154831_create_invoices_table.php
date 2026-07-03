<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('invoice_number');
            $table->string('status')->default('draft'); // draft | approved | sent | partial | paid | void
            $table->date('issue_date');
            $table->timestampTz('issue_time_utc')->nullable(); // MyInvois wants issue time
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('MYR');
            $table->decimal('fx_rate', 14, 6)->default(1);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('rounding', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->default(0);
            $table->text('notes')->nullable();
            // e-Invoice readiness (designed in from day one, transmission optional)
            $table->string('einvoice_type_code', 2)->default('01'); // 01 invoice, 02 CN, 03 DN, 04 refund
            $table->string('einvoice_status')->default('not_applicable');
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete(); // CN/DN link
            $table->timestamps();
            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
