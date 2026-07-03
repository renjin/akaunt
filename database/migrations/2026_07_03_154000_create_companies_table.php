<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_form'); // sdn_bhd | partnership | sole_prop | llp
            $table->string('brn')->nullable();          // SSM business registration no.
            $table->string('tin')->nullable();          // LHDN TIN
            $table->string('sst_registration_no')->nullable(); // null = SST-unregistered (default SME state)
            $table->string('msic_code', 5)->nullable(); // 5-digit MSIC
            $table->char('base_currency', 3)->default('MYR');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1); // per-company FYE
            $table->string('reporting_framework')->default('mpers'); // mpers | mfrs
            // Address (MyInvois-shaped)
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode', 10)->nullable();
            $table->char('country_code', 2)->default('MY');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // e-Invoice readiness flags (RM1m exemption — transmission is opt-in per company)
            $table->boolean('einvoice_enabled')->default(false);
            $table->boolean('einvoice_threshold_crossed')->default(false);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
