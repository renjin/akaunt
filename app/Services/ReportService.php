<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

class ReportService
{
    /** Per-account (debit, credit) sums in base currency, optionally date-bounded. */
    private function sums(Company $company, ?string $from = null, ?string $to = null): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(debit_base),0) AS d, COALESCE(SUM(credit_base),0) AS c')
            ->get()
            ->keyBy('account_id');
    }

    /** Trial balance: every account with activity, its net debit or credit balance. */
    public function trialBalance(Company $company, ?string $asOf = null): array
    {
        $sums = $this->sums($company, to: $asOf);
        $rows = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($company->accounts()->orderBy('code')->get() as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            $net = bcsub($s->d, $s->c, 2);
            $debit = bccomp($net, '0', 2) === 1 ? $net : '0.00';
            $credit = bccomp($net, '0', 2) === -1 ? bcmul($net, '-1', 2) : '0.00';
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;
            }
            $rows[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];
            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
        }

        return ['rows' => $rows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    /** P&L for a period: income and expense balances + net profit. */
    public function profitAndLoss(Company $company, string $from, string $to): array
    {
        $sums = $this->sums($company, $from, $to);
        $sections = ['income' => [], 'cogs' => [], 'expense' => []];
        $totals = ['income' => '0.00', 'cogs' => '0.00', 'expense' => '0.00'];

        foreach ($company->accounts()->whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            $balance = $account->type === 'income' ? bcsub($s->c, $s->d, 2) : bcsub($s->d, $s->c, 2);
            if (bccomp($balance, '0', 2) === 0) {
                continue;
            }
            $section = $account->type === 'income' ? 'income' : ($account->subtype === 'cogs' ? 'cogs' : 'expense');
            $sections[$section][] = ['account' => $account, 'balance' => $balance];
            $totals[$section] = bcadd($totals[$section], $balance, 2);
        }

        $grossProfit = bcsub($totals['income'], $totals['cogs'], 2);

        return [
            'sections' => $sections,
            'totals' => $totals,
            'gross_profit' => $grossProfit,
            'net_profit' => bcsub($grossProfit, $totals['expense'], 2),
        ];
    }

    /** Balance sheet as of a date. Undistributed P&L shows as Current Year Earnings. */
    public function balanceSheet(Company $company, string $asOf): array
    {
        $sums = $this->sums($company, to: $asOf);
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => '0.00', 'liability' => '0.00', 'equity' => '0.00'];
        $earnings = '0.00'; // income − expense, rolled into equity

        foreach ($company->accounts()->orderBy('code')->get() as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            if (in_array($account->type, ['income', 'expense'])) {
                // credit-positive net: income adds, expense (debit-heavy) subtracts
                $earnings = bcadd($earnings, bcsub($s->c, $s->d, 2), 2);
                continue;
            }
            $balance = $account->isDebitNormal() ? bcsub($s->d, $s->c, 2) : bcsub($s->c, $s->d, 2);
            if (bccomp($balance, '0', 2) === 0) {
                continue;
            }
            $sections[$account->type][] = ['account' => $account, 'balance' => $balance];
            $totals[$account->type] = bcadd($totals[$account->type], $balance, 2);
        }

        if (bccomp($earnings, '0', 2) !== 0) {
            $sections['equity'][] = ['account' => null, 'label' => 'Current Year Earnings', 'balance' => $earnings];
            $totals['equity'] = bcadd($totals['equity'], $earnings, 2);
        }

        return [
            'sections' => $sections,
            'totals' => $totals,
            'liabilities_plus_equity' => bcadd($totals['liability'], $totals['equity'], 2),
        ];
    }

    /** General ledger: journal lines for one account over a period, with running balance. */
    public function generalLedger(Company $company, Account $account, string $from, string $to): array
    {
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_lines.account_id', $account->id)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*', 'journal_entries.entry_date', 'journal_entries.description AS entry_description', 'journal_entries.reference')
            ->get();

        $running = $account->balance(to: date('Y-m-d', strtotime($from . ' -1 day')));
        $rows = [];
        foreach ($lines as $line) {
            $delta = $account->isDebitNormal()
                ? bcsub($line->debit_base, $line->credit_base, 2)
                : bcsub($line->credit_base, $line->debit_base, 2);
            $running = bcadd($running, $delta, 2);
            $rows[] = ['line' => $line, 'balance' => $running];
        }

        return ['rows' => $rows, 'closing' => $running];
    }

    /** SST-02 helper: output tax collected per tax code for a taxable period. */
    public function sstOutputSummary(Company $company, string $from, string $to): array
    {
        $rows = \App\Models\InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->join('tax_codes', 'tax_codes.id', '=', 'invoice_lines.tax_code_id')
            ->where('invoices.company_id', $company->id)
            ->whereNotIn('invoices.status', ['draft', 'void'])
            ->whereBetween('invoices.issue_date', [$from, $to])
            ->groupBy('tax_codes.id', 'tax_codes.name', 'tax_codes.rate', 'tax_codes.tax_type')
            ->selectRaw('tax_codes.name, tax_codes.rate, tax_codes.tax_type,
                COALESCE(SUM(invoice_lines.line_total),0) AS taxable,
                COALESCE(SUM(invoice_lines.tax_amount),0) AS tax')
            ->orderBy('tax_codes.name')
            ->get();

        return [
            'rows' => $rows,
            'total_taxable' => $rows->reduce(fn ($c, $r) => bcadd($c, $r->taxable, 2), '0.00'),
            'total_tax' => $rows->reduce(fn ($c, $r) => bcadd($c, $r->tax, 2), '0.00'),
        ];
    }

    /** Aged payables, mirroring aged receivables buckets. */
    public function agedPayables(Company $company): Collection
    {
        $today = today();

        return $company->bills()
            ->with('party')
            ->whereIn('status', ['approved', 'partial'])
            ->get()
            ->groupBy(fn ($bill) => $bill->party->name)
            ->map(function (Collection $bills) use ($today) {
                $buckets = ['current' => '0.00', 'b30' => '0.00', 'b60' => '0.00', 'b90' => '0.00', 'total' => '0.00'];
                foreach ($bills as $bill) {
                    $due = $bill->due_date ?? $bill->bill_date;
                    $days = (int) $due->diffInDays($today, false);
                    $bucket = match (true) {
                        $days <= 0 => 'current',
                        $days <= 30 => 'b30',
                        $days <= 60 => 'b60',
                        default => 'b90',
                    };
                    $buckets[$bucket] = bcadd($buckets[$bucket], $bill->balance_due, 2);
                    $buckets['total'] = bcadd($buckets['total'], $bill->balance_due, 2);
                }

                return $buckets;
            })
            ->sortKeys();
    }
}
