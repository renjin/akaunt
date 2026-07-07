<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('po_number')->nullable()->after('invoice_number');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->text('invoice_notes_default')->nullable();
            $table->integer('payment_terms_days_default')->nullable()->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('po_number');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['invoice_notes_default', 'payment_terms_days_default']);
        });
    }
};
