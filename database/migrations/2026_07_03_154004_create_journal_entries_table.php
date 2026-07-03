<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->string('description')->nullable();
            $table->nullableMorphs('source'); // invoice | bill | payment | bank_transaction | null = manual
            $table->timestamps();
            $table->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
