<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(private PostingService $poster) {}

    /** Recompute line tax + header totals from the lines. Call after lines change. */
    public function calculateTotals(Invoice $invoice): Invoice
    {
        // Pass 1: per-line net (qty*price - line discount), and the subtotal.
        $subtotal = '0.00';
        foreach ($invoice->lines as $line) {
            $lineTotal = bcsub(
                bcmul((string) $line->quantity, (string) $line->unit_price, 2),
                (string) $line->discount,
                2
            );
            $line->forceFill(['line_total' => $lineTotal])->save();
            $subtotal = bcadd($subtotal, $lineTotal, 2);
        }

        // Whole-invoice discount, applied BEFORE tax: prorate across lines so
        // each line's SST is charged on its share of the discounted subtotal.
        $discountTotal = $this->discountAmount($invoice, $subtotal);
        $shares = $this->discountShares($invoice, $subtotal, $discountTotal);

        // Pass 2: tax on each line's net (line_total - its share of the invoice discount).
        $taxTotal = '0.00';
        foreach ($invoice->lines as $line) {
            $net = bcsub((string) $line->line_total, $shares[$line->id] ?? '0.00', 2);

            $taxAmount = '0.00';
            foreach ($line->effectiveTaxCodes() as $taxCode) {
                $taxAmount = bcadd($taxAmount, $taxCode->calculate((float) $net), 2);
            }

            // Keep the legacy single tax_code_id populated with the FIRST selected code
            // (backward compat for e-Invoice/reports).
            $firstCodeId = $line->effectiveTaxCodeIds()[0] ?? null;

            $line->forceFill([
                'tax_amount' => $taxAmount,
                'tax_code_id' => $firstCodeId,
            ])->save();

            $taxTotal = bcadd($taxTotal, $taxAmount, 2);
        }

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => bcadd(
                bcsub(bcadd($subtotal, $taxTotal, 2), $discountTotal, 2),
                (string) $invoice->rounding,
                2
            ),
        ])->save();

        return $invoice->refresh();
    }

    /** Resolve the whole-invoice discount to a capped RM amount from its type/value. */
    private function discountAmount(Invoice $invoice, string $subtotal): string
    {
        $value = (string) ($invoice->discount_value ?? '0');
        if ((float) $value <= 0 || (float) $subtotal <= 0) {
            return '0.00';
        }

        $amount = $invoice->discount_type === 'percent'
            ? bcdiv(bcmul($subtotal, $value, 4), '100', 2)
            : bcadd($value, '0', 2);

        // Never discount below zero.
        return (float) $amount > (float) $subtotal ? bcadd($subtotal, '0', 2) : $amount;
    }

    /**
     * Split the invoice discount across lines by line_total share, giving any
     * rounding remainder to the last line so the shares sum to exactly $discountTotal.
     *
     * @return array<int, string> line id => discount share
     */
    private function discountShares(Invoice $invoice, string $subtotal, string $discountTotal): array
    {
        $shares = [];
        $lines = $invoice->lines->values();

        if ((float) $discountTotal <= 0 || (float) $subtotal <= 0) {
            foreach ($lines as $line) {
                $shares[$line->id] = '0.00';
            }

            return $shares;
        }

        $allocated = '0.00';
        $last = $lines->count() - 1;
        foreach ($lines as $i => $line) {
            if ($i === $last) {
                $shares[$line->id] = bcsub($discountTotal, $allocated, 2);
                break;
            }
            $share = bcdiv(bcmul($discountTotal, (string) $line->line_total, 4), $subtotal, 2);
            $shares[$line->id] = $share;
            $allocated = bcadd($allocated, $share, 2);
        }

        return $shares;
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

        return DB::transaction(function () use ($invoice) {
            $this->postToLedger($invoice);
            $invoice->forceFill([
                'status' => 'approved',
                'issue_time_utc' => $invoice->issue_time_utc ?? now('UTC'),
            ])->save();

            return $invoice;
        });
    }

    /**
     * Re-post an edited, already-posted invoice (Wave-style editing): replace its
     * ledger entry with one built from the current lines, then re-derive the
     * payment status from what has actually been paid.
     */
    public function repost(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, ['draft', 'void'], true)) {
            throw new InvalidArgumentException("Only posted invoices can be re-posted (is: {$invoice->status}).");
        }
        if ($invoice->lines()->count() === 0) {
            throw new InvalidArgumentException('Cannot re-post an invoice with no lines.');
        }

        $invoice->refresh()->load('lines.taxCode');
        $this->calculateTotals($invoice);

        return DB::transaction(function () use ($invoice) {
            $this->poster->unpost($invoice);
            $this->postToLedger($invoice);

            $paid = (float) $invoice->amount_paid;
            $status = match (true) {
                $paid <= 0 => in_array($invoice->status, ['sent', 'approved'], true) ? $invoice->status : 'approved',
                $paid < (float) $invoice->total => 'partial',
                default => 'paid',
            };
            $invoice->forceFill(['status' => $status])->save();

            return $invoice;
        });
    }

    /** Build and post the balanced ledger entry for the invoice's current lines. */
    private function postToLedger(Invoice $invoice): void
    {
        $company = $invoice->company;
        $ar = $company->systemAccount('accounts_receivable');
        $defaultIncome = $company->accounts()->where('subtype', 'operating_revenue')->orderBy('code')->firstOrFail();

        $lines = [[
            'account_id' => $ar->id,
            'debit' => $invoice->total,
            'currency' => $invoice->currency,
            'fx_rate' => $invoice->fx_rate,
        ]];

        // Credit income NET of the whole-invoice discount (prorated the same way as
        // calculateTotals), so income accounts reflect actual revenue and the entry balances.
        $shares = $this->discountShares($invoice, (string) $invoice->subtotal, (string) $invoice->discount_total);

        $incomeByAccount = [];
        $taxByAccount = [];
        foreach ($invoice->lines as $line) {
            $net = bcsub((string) $line->line_total, $shares[$line->id] ?? '0.00', 2);

            $incomeId = $line->income_account_id ?? $line->item?->income_account_id ?? $defaultIncome->id;
            $incomeByAccount[$incomeId] = bcadd($incomeByAccount[$incomeId] ?? '0.00', $net, 2);

            // Credit each selected tax code's payable account (grouped across lines).
            foreach ($line->effectiveTaxCodes() as $taxCode) {
                $codeTax = $taxCode->calculate((float) $net);
                if ((float) $codeTax === 0.0) {
                    continue;
                }
                $taxAccountId = $taxCode->sst_payable_account_id
                    ?? $company->systemAccount('sst_payable')->id;
                $taxByAccount[$taxAccountId] = bcadd($taxByAccount[$taxAccountId] ?? '0.00', $codeTax, 2);
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

        $this->poster->post(
            $company,
            $invoice->issue_date->toDateString(),
            $lines,
            "Invoice {$invoice->invoice_number} — {$invoice->party->name}",
            $invoice->invoice_number,
            $invoice,
        );
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
    public function nextNumber(Company $company, string $prefix = 'INV-'): string
    {
        $last = $company->invoices()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('invoice_number');

        $n = $last ? (int) preg_replace('/\D/', '', substr($last, strlen($prefix))) : 0;

        return $prefix.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }
}
