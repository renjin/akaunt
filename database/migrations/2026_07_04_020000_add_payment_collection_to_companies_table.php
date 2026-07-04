<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // EMVCo QR payload string from the company's bank (DuitNow QR merchant enrolment)
            $table->text('duitnow_qr_payload')->nullable();
            // Optional hosted payment link (Billplz/toyyibPay/Stripe payment link, etc.)
            $table->string('payment_link')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['duitnow_qr_payload', 'payment_link']);
        });
    }
};
