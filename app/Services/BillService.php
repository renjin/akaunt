<?php

namespace App\Services;

use App\Models\Bill;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BillService
{
    public function __construct(private PostingService $poster)
    {
    }

    public function calculateTotals(Bill $bill): Bill
    {
        $subtotal = '0.00';
        $taxTotal = '0.00';

        foreach ($bill->lines as $line) {
            $lineTotal = bcmul((string) $line->quantity, (string) $line->unit_price, 2);
            $taxAmount = $line->taxCode ? $line->taxCode->calculate((float) $lineTotal) : '0.00';

            $line->forceFill(['line_total' => $lineTotal, 'tax_amount' => $taxAmount])->save();
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $taxTotal = bcadd($taxTotal, $taxAmount, 2);
        }

        $bill->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 2),
        ])->save();

        return $bill->refresh();
    }

    /**
     * Approve a draft bill and post it.
     * SST is single-stage with NO input tax credit: SST paid on purchases is
     * part of the expense (Dr Expense incl. tax / Cr Accounts Payable) —
     * never a recoverable asset.
     */
    public function approve(Bill $bill): Bill
    {
        if ($bill->status !== 'draft') {
            throw new InvalidArgumentException("Only draft bills can be approved (is: {$bill->status}).");
        }
        if ($bill->lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot approve a bill with no lines.');
        }

        $this->calculateTotals($bill);
        $company = $bill->company;
        $ap = $company->systemAccount('accounts_payable');
        $defaultExpense = $company->accounts()->where('code', '6900')->firstOrFail();

        // Debit expense per line — line total PLUS its SST (tax folds into the cost)
        $expenseByAccount = [];
        foreach ($bill->lines as $line) {
            $expenseId = $line->expense_account_id ?? $line->item?->expense_account_id ?? $defaultExpense->id;
            $gross = bcadd((string) $line->line_total, (string) $line->tax_amount, 2);
            $expenseByAccount[$expenseId] = bcadd($expenseByAccount[$expenseId] ?? '0.00', $gross, 2);
        }

        $lines = [];
        foreach ($expenseByAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount, 'currency' => $bill->currency, 'fx_rate' => $bill->fx_rate];
        }
        $lines[] = ['account_id' => $ap->id, 'credit' => $bill->total, 'currency' => $bill->currency, 'fx_rate' => $bill->fx_rate];

        return DB::transaction(function () use ($bill, $company, $lines) {
            $this->poster->post(
                $company,
                $bill->bill_date->toDateString(),
                $lines,
                "Bill {$bill->bill_number} — {$bill->party->name}",
                $bill->bill_number,
                $bill,
            );
            $bill->forceFill(['status' => 'approved'])->save();

            return $bill;
        });
    }

    public function void(Bill $bill): Bill
    {
        if ((float) $bill->amount_paid > 0) {
            throw new InvalidArgumentException('Cannot void a bill with recorded payments.');
        }

        return DB::transaction(function () use ($bill) {
            $this->poster->unpost($bill);
            $bill->forceFill(['status' => 'void'])->save();

            return $bill;
        });
    }
}
