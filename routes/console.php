<?php

use App\Jobs\GenerateRecurringInvoicesJob;
use App\Jobs\PollEinvoiceStatusJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// LHDN validation is near-real-time; every 15 min is plenty for SME volume.
Schedule::job(new PollEinvoiceStatusJob)->everyFifteenMinutes();

// Recurring invoices only need to run once a day.
Schedule::job(new GenerateRecurringInvoicesJob)->dailyAt('06:00');

// Webhook-miss fallback: settle completed HitPay checkouts. Modest cadence — 70 req/min endpoint limit.
Schedule::call(function () {
    \App\Models\Company::query()
        ->whereNotNull('hitpay_api_key')
        ->whereHas('invoices', fn ($q) => $q->whereNotNull('hitpay_payment_request_id')
            ->whereIn('status', ['approved', 'sent', 'partial']))
        ->each(fn ($company) => app(\App\Services\HitPay\HitPayService::class)->pollPending($company));
})->hourly()->name('hitpay-poll-fallback');
