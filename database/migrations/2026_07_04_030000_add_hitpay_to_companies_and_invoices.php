<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('hitpay_api_key')->nullable();  // encrypted cast
            $table->text('hitpay_salt')->nullable();     // encrypted cast
            $table->string('hitpay_environment')->default('sandbox'); // sandbox | production
            $table->foreignId('hitpay_deposit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_url')->nullable();            // hosted HitPay checkout URL
            $table->string('hitpay_payment_request_id')->nullable();
            $table->index('hitpay_payment_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_url', 'hitpay_payment_request_id']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hitpay_deposit_account_id');
            $table->dropColumn(['hitpay_api_key', 'hitpay_salt', 'hitpay_environment']);
        });
    }
};
