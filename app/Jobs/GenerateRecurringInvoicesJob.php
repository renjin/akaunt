<?php

namespace App\Jobs;

use App\Services\RecurringInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRecurringInvoicesJob implements ShouldQueue
{
    use Queueable;

    public function handle(RecurringInvoiceService $service): void
    {
        $service->generateAllDue();
    }
}
