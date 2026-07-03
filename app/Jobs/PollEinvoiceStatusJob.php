<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Einvoice\EinvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Middleware has no webhooks — status is poll-only. Runs on the scheduler. */
class PollEinvoiceStatusJob implements ShouldQueue
{
    use Queueable;

    public function handle(EinvoiceService $service): void
    {
        Company::query()
            ->where('einvoice_enabled', true)
            ->whereHas('einvoiceCredential')
            ->whereHas('invoices.submissions', fn ($q) => $q->where('status', 'submitted'))
            ->each(fn (Company $company) => $service->pollStatuses($company));
    }
}
