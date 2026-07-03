<?php

namespace App\Services;

use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single write-path into the ledger. Every invoice, bill, payment and
 * bank transaction posts through here. Enforces Σdebit = Σcredit.
 */
class PostingService
{
    /**
     * @param array<int, array{account_id:int, debit?:string|float, credit?:string|float, currency?:string, fx_rate?:string|float, memo?:string}> $lines
     */
    public function post(
        Company $company,
        string $entryDate,
        array $lines,
        ?string $description = null,
        ?string $reference = null,
        ?Model $source = null,
    ): JournalEntry {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $normalized = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $line) {
            $debit = number_format((float) ($line['debit'] ?? 0), 2, '.', '');
            $credit = number_format((float) ($line['credit'] ?? 0), 2, '.', '');
            $fx = (float) ($line['fx_rate'] ?? 1);

            if ((float) $debit < 0 || (float) $credit < 0) {
                throw new InvalidArgumentException('Debit/credit amounts cannot be negative.');
            }
            if ((float) $debit > 0 && (float) $credit > 0) {
                throw new InvalidArgumentException('A line must be a debit or a credit, not both.');
            }
            if ((float) $debit == 0 && (float) $credit == 0) {
                continue; // skip empty lines silently (UI convenience)
            }

            $debitBase = number_format((float) $debit * $fx, 2, '.', '');
            $creditBase = number_format((float) $credit * $fx, 2, '.', '');

            $normalized[] = [
                'account_id' => $line['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'currency' => $line['currency'] ?? $company->base_currency ?? 'MYR',
                'fx_rate' => $fx,
                'debit_base' => $debitBase,
                'credit_base' => $creditBase,
                'memo' => $line['memo'] ?? null,
            ];

            $totalDebit = bcadd($totalDebit, $debitBase, 2);
            $totalCredit = bcadd($totalCredit, $creditBase, 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new InvalidArgumentException(
                "Unbalanced entry: debits {$totalDebit} != credits {$totalCredit} (base currency)."
            );
        }

        return DB::transaction(function () use ($company, $entryDate, $description, $reference, $source, $normalized) {
            $entry = $company->journalEntries()->create([
                'entry_date' => $entryDate,
                'description' => $description,
                'reference' => $reference,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);
            $entry->lines()->createMany($normalized);

            return $entry;
        });
    }

    /** Reverse (delete) the ledger impact of a source document, e.g. when voiding. */
    public function unpost(Model $source): void
    {
        JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get()
            ->each(fn (JournalEntry $entry) => $entry->delete());
    }
}
