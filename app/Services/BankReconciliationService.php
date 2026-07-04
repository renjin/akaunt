<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reconciles against the GENERAL LEDGER for the bank account, not the
 * bank_transactions table — invoice/bill payments post straight to the
 * ledger without ever creating a bank_transactions row, so that table alone
 * would miss most bank activity.
 */
class BankReconciliationService
{
    /** Lines not yet reconciled, dated on/before the statement date. */
    public function unreconciledLines(Account $account, string $upToDate): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.reconciled', false)
            ->where('journal_entries.entry_date', '<=', $upToDate)
            ->orderBy('journal_entries.entry_date')
            ->select('journal_lines.*', 'journal_entries.entry_date', 'journal_entries.description AS entry_description', 'journal_entries.reference')
            ->get();
    }

    /** Balance already locked in from prior reconciliation sessions. */
    public function previouslyReconciledBalance(Account $account, string $upToDate): string
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.reconciled', true)
            ->where('journal_entries.entry_date', '<=', $upToDate)
            ->selectRaw('COALESCE(SUM(debit_base),0) AS d, COALESCE(SUM(credit_base),0) AS c')
            ->first();

        return bcsub($sums->d, $sums->c, 2); // bank is debit-normal
    }

    /** @param array<int> $lineIds journal_line ids the user ticked as cleared */
    public function clearedBalance(Account $account, string $upToDate, array $lineIds): string
    {
        $balance = $this->previouslyReconciledBalance($account, $upToDate);
        if (empty($lineIds)) {
            return $balance;
        }

        $sums = JournalLine::query()
            ->whereIn('id', $lineIds)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit_base),0) AS d, COALESCE(SUM(credit_base),0) AS c')
            ->first();

        return bcadd($balance, bcsub($sums->d, $sums->c, 2), 2);
    }

    /** @param array<int> $lineIds journal_line ids to mark reconciled */
    public function finish(Account $account, string $upToDate, string $statementBalance, array $lineIds): int
    {
        $cleared = $this->clearedBalance($account, $upToDate, $lineIds);
        if (bccomp($cleared, number_format((float) $statementBalance, 2, '.', ''), 2) !== 0) {
            throw new InvalidArgumentException(
                "Cleared balance {$cleared} does not match the statement balance {$statementBalance}. Difference: " . bcsub($statementBalance, $cleared, 2)
            );
        }

        return JournalLine::query()
            ->whereIn('id', $lineIds)
            ->where('account_id', $account->id)
            ->update(['reconciled' => true, 'reconciled_at' => now()]);
    }
}
