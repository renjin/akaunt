<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('customer'); // customer | vendor | both
            $table->string('name');
            // Typed identity — matches MyInvois schemeID set (never a single "Tax ID" field)
            $table->string('registration_scheme')->nullable(); // BRN | NRIC | PASSPORT | ARMY
            $table->string('registration_number')->nullable();
            $table->string('tin')->nullable();
            $table->string('sst_registration_no')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode', 10)->nullable();
            $table->char('country_code', 2)->default('MY');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
