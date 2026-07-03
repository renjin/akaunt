<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One credential pair = one legal entity/TIN at the middleware (einvoiceapp.my)
        Schema::create('einvoice_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('keyid');
            $table->text('keysecret'); // encrypted cast
            $table->string('myinvois_id')->nullable();
            $table->string('environment')->default('staging'); // staging | production
            $table->timestamps();
        });

        // The human gate + middleware seam. Nothing transmits until a user approves.
        Schema::create('einvoice_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending_approval');
            // pending_approval | approved | submitted | validated | rejected | cancelled | failed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('middleware_invoice_code')->nullable(); // eInvoiceCode from create response
            $table->string('einvoice_url')->nullable();            // hosted invoice URL
            $table->string('lhdn_uuid')->nullable();               // only exposed for notes today
            $table->string('qr_path')->nullable();                 // stored QR JPEG once validated
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->jsonb('payload_snapshot')->nullable();  // exactly what we sent
            $table->jsonb('response_snapshot')->nullable(); // what came back
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_submissions');
        Schema::dropIfExists('einvoice_credentials');
    }
};
