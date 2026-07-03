<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(private PostingService $poster)
    {
    }

    /** Recompute line tax + header totals from the lines. Call after lines change. */
    public function calculateTotals(Invoice $invoice): Invoice
    {
        $subtotal = '0.00';
        $taxTotal = '0.00';

        foreach ($invoice->lines as $line) {
            $lineTotal = bcsub(
                bcmul((string) $line->quantity, (string) $line->unit_price, 2),
                (string) $line->discount,
                2
            );
            $taxAmount = $line->taxCode ? $line->taxCode->calculate((float) $lineTotal) : '0.00';

            $line->forceFill(['line_total' => $lineTotal, 'tax_amount' => $taxAmount])->save();

            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $taxTotal = bcadd($taxTotal, $taxAmount, 2);
        }

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => bcadd(bcadd($subtotal, $taxTotal, 2), (string) $invoice->rounding, 2),
        ])->save();

        return $invoice->refresh();
    }

    /**
     * Approve a draft invoice: locks it and posts to the ledger.
     * Dr Accounts Receivable (total) / Cr income per line / Cr SST Payable (tax).
     */
    public function approve(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException("Only draft invoices can be approved (is: {$invoice->status}).");
        }
        if ($invoice->lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot approve an invoice with no lines.');
        }

        $this->calculateTotals($invoice);
        $company = $invoice->company;
        $ar = $company->systemAccount('accounts_receivable');
        $defaultIncome = $company->accounts()->where('subtype', 'operating_revenue')->orderBy('code')->firstOrFail();

        $lines = [[
            'account_id' => $ar->id,
            'debit' => $invoice->total,
            'currency' => $invoice->currency,
            'fx_rate' => $invoice->fx_rate,
        ]];

        // Credit income, grouped by account
        $incomeByAccount = [];
        $taxByAccount = [];
        foreach ($invoice->lines as $line) {
            $incomeId = $line->income_account_id ?? $line->item?->income_account_id ?? $defaultIncome->id;
            $incomeByAccount[$incomeId] = bcadd($incomeByAccount[$incomeId] ?? '0.00', (string) $line->line_total, 2);

            if ((float) $line->tax_amount > 0) {
                $taxAccountId = $line->taxCode?->sst_payable_account_id
                    ?? $company->systemAccount('sst_payable')->id;
                $taxByAccount[$taxAccountId] = bcadd($taxByAccount[$taxAccountId] ?? '0.00', (string) $line->tax_amount, 2);
            }
        }

        foreach ($incomeByAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'credit' => $amount, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate];
        }
        foreach ($taxByAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'credit' => $amount, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate];
        }
        if ((float) $invoice->rounding != 0) {
            // ponytail: rounding difference goes to General Expenses; a dedicated rounding account when it matters
            $general = $company->accounts()->where('code', '6900')->firstOrFail();
            $r = (string) $invoice->rounding;
            $lines[] = (float) $r > 0
                ? ['account_id' => $general->id, 'credit' => $r, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate]
                : ['account_id' => $general->id, 'debit' => bcmul($r, '-1', 2), 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate];
        }

        return DB::transaction(function () use ($invoice, $company, $lines) {
            $this->poster->post(
                $company,
                $invoice->issue_date->toDateString(),
                $lines,
                "Invoice {$invoice->invoice_number} — {$invoice->party->name}",
                $invoice->invoice_number,
                $invoice,
            );
            $invoice->forceFill([
                'status' => 'approved',
                'issue_time_utc' => $invoice->issue_time_utc ?? now('UTC'),
            ])->save();

            return $invoice;
        });
    }

    /** Void an unpaid invoice: reverses its ledger entry. */
    public function void(Invoice $invoice): Invoice
    {
        if ((float) $invoice->amount_paid > 0) {
            throw new InvalidArgumentException('Cannot void an invoice with recorded payments.');
        }

        return DB::transaction(function () use ($invoice) {
            $this->poster->unpost($invoice);
            $invoice->forceFill(['status' => 'void'])->save();

            return $invoice;
        });
    }

    /** Next sequential invoice number for a company, e.g. INV-00042. */
    public function nextNumber(\App\Models\Company $company, string $prefix = 'INV-'): string
    {
        $last = $company->invoices()
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('invoice_number');

        $n = $last ? (int) preg_replace('/\D/', '', substr($last, strlen($prefix))) : 0;

        return $prefix . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }
}
