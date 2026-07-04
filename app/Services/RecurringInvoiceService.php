<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Generates and auto-approves the next invoice for a due recurring template.
 * ponytail: auto-approve (posts to the ledger immediately) rather than
 * queuing for manual review — the recurrence itself IS the standing human
 * authorization; the irreversible-transmission gate that matters is e-Invoice
 * submission, which stays separate and still requires approval.
 */
class RecurringInvoiceService
{
    public function __construct(private InvoiceService $invoices)
    {
    }

    public function generate(RecurringInvoice $recurring): Invoice
    {
        return DB::transaction(function () use ($recurring) {
            $issueDate = $recurring->next_run_date->copy();

            $invoice = $recurring->company->invoices()->create([
                'party_id' => $recurring->party_id,
                'invoice_number' => $this->invoices->nextNumber($recurring->company),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays($recurring->due_days)->toDateString(),
                'currency' => $recurring->currency,
                'notes' => $recurring->notes,
            ]);

            foreach ($recurring->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_code_id' => $line->tax_code_id,
                    'classification_code' => $line->classification_code,
                    'income_account_id' => $line->income_account_id,
                ]);
            }

            $this->invoices->approve($invoice->refresh());
            $recurring->advance();

            return $invoice;
        });
    }

    /** Runs on the scheduler: generates every due template across all companies. */
    public function generateAllDue(): int
    {
        $count = 0;
        RecurringInvoice::query()->where('active', true)
            ->where('next_run_date', '<=', today())
            ->with('lines')
            ->chunkById(50, function ($batch) use (&$count) {
                foreach ($batch as $recurring) {
                    if ($recurring->isDue() && $recurring->lines->isNotEmpty()) {
                        $this->generate($recurring);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
