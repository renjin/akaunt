<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete(); // the bank/cash CoA account
            $table->date('txn_date');
            $table->string('description');
            $table->decimal('amount', 16, 2); // always positive; direction says which way
            $table->string('direction'); // in | out
            $table->string('status')->default('unmatched'); // unmatched | categorized | reconciled
            $table->foreignId('category_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('matched_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('import_batch')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['account_id', 'txn_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
