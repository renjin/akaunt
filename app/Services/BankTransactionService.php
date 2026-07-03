<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BankTransactionService
{
    public function __construct(private PostingService $poster)
    {
    }

    /**
     * Categorize an unmatched bank transaction to a CoA account and post it.
     * money in:  Dr Bank / Cr category (e.g. income)
     * money out: Dr category (e.g. expense) / Cr Bank
     */
    public function categorize(BankTransaction $txn, Account $category): BankTransaction
    {
        if ($txn->status !== 'unmatched') {
            throw new InvalidArgumentException('Transaction is already categorized or reconciled.');
        }

        return DB::transaction(function () use ($txn, $category) {
            $bank = ['account_id' => $txn->account_id];
            $cat = ['account_id' => $category->id];

            $lines = $txn->direction === 'in'
                ? [$bank + ['debit' => $txn->amount], $cat + ['credit' => $txn->amount]]
                : [$cat + ['debit' => $txn->amount], $bank + ['credit' => $txn->amount]];

            $this->poster->post(
                $txn->company,
                $txn->txn_date->toDateString(),
                $lines,
                $txn->description,
                null,
                $txn,
            );

            $txn->forceFill(['status' => 'categorized', 'category_account_id' => $category->id])->save();

            return $txn;
        });
    }

    /**
     * Mark a transaction as matched to an existing payment (already posted via
     * the invoice/bill flow) — no second posting, just links + reconciles it.
     */
    public function matchToPayment(BankTransaction $txn, \App\Models\Payment $payment): BankTransaction
    {
        if ($txn->status !== 'unmatched') {
            throw new InvalidArgumentException('Transaction is already categorized or reconciled.');
        }

        $txn->forceFill(['status' => 'reconciled', 'matched_payment_id' => $payment->id])->save();

        return $txn;
    }

    /**
     * Import bank CSV rows: date, description, amount (signed: negative = out).
     * Returns [imported, skipped]. Dedupe on (account, date, description, amount).
     */
    public function importCsv(Company $company, Account $bankAccount, string $csv): array
    {
        $batch = 'csv-' . Str::uuid()->toString();
        $imported = 0;
        $skipped = 0;

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        foreach ($rows as $i => $row) {
            if (count($row) < 3) {
                $skipped++;
                continue;
            }
            [$date, $description, $amount] = [trim($row[0]), trim($row[1]), trim($row[2])];
            // skip header row
            if ($i === 0 && ! is_numeric(str_replace([',', '-'], '', $amount))) {
                continue;
            }
            $ts = strtotime($date);
            $value = (float) str_replace(',', '', $amount);
            if ($ts === false || $value == 0.0) {
                $skipped++;
                continue;
            }

            $attrs = [
                'account_id' => $bankAccount->id,
                'txn_date' => date('Y-m-d', $ts),
                'description' => $description,
                'amount' => number_format(abs($value), 2, '.', ''),
                'direction' => $value > 0 ? 'in' : 'out',
            ];

            if ($company->bankTransactions()->where($attrs)->exists()) {
                $skipped++;
                continue;
            }

            $company->bankTransactions()->create($attrs + ['import_batch' => $batch]);
            $imported++;
        }

        return [$imported, $skipped];
    }
}
