<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
            $table->unsignedInteger('reminders_sent_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['last_sent_at', 'last_reminder_at', 'reminders_sent_count']);
        });
    }
};
