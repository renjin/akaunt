<?php

use App\Jobs\PollEinvoiceStatusJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// LHDN validation is near-real-time; every 15 min is plenty for SME volume.
Schedule::job(new PollEinvoiceStatusJob)->everyFifteenMinutes();
