<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A credit note is stored as another `invoices` row (einvoice_type_code=02,
 * original_invoice_id set) so it reuses the same lines/tax/e-Invoice plumbing.
 * ponytail: credit notes only reduce an invoice's still-outstanding balance —
 * crediting an already fully-paid invoice needs a refund, which is out of
 * scope here (no cash refund flow exists yet).
 */
class CreditNoteService
{
    public function __construct(private PostingService $poster, private InvoiceService $invoices)
    {
    }

    public function create(Invoice $original, string $issueDate, array $lines): Invoice
    {
        if (! $original->isPosted()) {
            throw new InvalidArgumentException('Cannot credit a draft or void invoice.');
        }

        $creditNote = $original->company->invoices()->create([
            'party_id' => $original->party_id,
            'invoice_number' => $this->invoices->nextNumber($original->company, 'CN-'),
            'issue_date' => $issueDate,
            'currency' => $original->currency,
            'fx_rate' => $original->fx_rate,
            'einvoice_type_code' => '02',
            'original_invoice_id' => $original->id,
        ]);
        foreach ($lines as $line) {
            $creditNote->lines()->create($line);
        }

        return $creditNote->refresh();
    }

    /** Reverses Dr income / Dr SST payable / Cr AR — the mirror image of an invoice approval. */
    public function approve(Invoice $creditNote): Invoice
    {
        if (! $creditNote->isCreditNote()) {
            throw new InvalidArgumentException('Not a credit note.');
        }
        if ($creditNote->status !== 'draft') {
            throw new InvalidArgumentException("Only draft credit notes can be approved (is: {$creditNote->status}).");
        }
        if ($creditNote->lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot approve a credit note with no lines.');
        }

        $this->invoices->calculateTotals($creditNote);
        $original = $creditNote->originalInvoice;
        $remaining = bcsub($original->total, $original->amount_paid, 2);
        if (bccomp($creditNote->total, $remaining, 2) === 1) {
            throw new InvalidArgumentException(
                "Credit amount {$creditNote->total} exceeds the original invoice's outstanding balance {$remaining}. Refunds for paid amounts are not supported."
            );
        }

        $company = $creditNote->company;
        $ar = $company->systemAccount('accounts_receivable');
        $defaultIncome = $company->accounts()->where('subtype', 'operating_revenue')->orderBy('code')->firstOrFail();

        $incomeByAccount = [];
        $taxByAccount = [];
        foreach ($creditNote->lines as $line) {
            $incomeId = $line->income_account_id ?? $line->item?->income_account_id ?? $defaultIncome->id;
            $incomeByAccount[$incomeId] = bcadd($incomeByAccount[$incomeId] ?? '0.00', (string) $line->line_total, 2);
            if ((float) $line->tax_amount > 0) {
                $taxAccountId = $line->taxCode?->sst_payable_account_id ?? $company->systemAccount('sst_payable')->id;
                $taxByAccount[$taxAccountId] = bcadd($taxByAccount[$taxAccountId] ?? '0.00', (string) $line->tax_amount, 2);
            }
        }

        $lines = [];
        foreach ($incomeByAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount, 'currency' => $creditNote->currency, 'fx_rate' => $creditNote->fx_rate];
        }
        foreach ($taxByAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount, 'currency' => $creditNote->currency, 'fx_rate' => $creditNote->fx_rate];
        }
        $lines[] = ['account_id' => $ar->id, 'credit' => $creditNote->total, 'currency' => $creditNote->currency, 'fx_rate' => $creditNote->fx_rate];

        return DB::transaction(function () use ($creditNote, $original, $company, $lines) {
            $this->poster->post(
                $company,
                $creditNote->issue_date->toDateString(),
                $lines,
                "Credit note {$creditNote->invoice_number} — {$creditNote->party->name}",
                $creditNote->invoice_number,
                $creditNote,
            );
            $creditNote->forceFill(['status' => 'approved'])->save();

            $newTotal = bcsub($original->total, $creditNote->total, 2);
            $original->forceFill([
                'total' => $newTotal,
                'status' => bccomp($newTotal, $original->amount_paid, 2) <= 0 ? 'paid' : $original->status,
            ])->save();

            return $creditNote;
        });
    }
}
