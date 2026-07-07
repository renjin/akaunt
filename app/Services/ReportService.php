<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\InvoiceLine;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
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

    /** Balance sheet as of a date. Prior-year P&L shows as Retained Earnings, current FY as Current Year Earnings. */
    public function balanceSheet(Company $company, string $asOf): array
    {
        $sums = $this->sums($company, to: $asOf);

        // Current financial year start (per-company FY start month), for splitting equity earnings.
        $fyStart = Carbon::parse($asOf)
            ->setDay(1)->setMonth((int) ($company->fiscal_year_start_month ?? 1));
        if ($fyStart->gt(Carbon::parse($asOf))) {
            $fyStart->subYear();
        }
        $priorSums = $this->sums($company, to: $fyStart->copy()->subDay()->toDateString());

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => '0.00', 'liability' => '0.00', 'equity' => '0.00'];
        $earnings = '0.00'; // income − expense to asOf, rolled into equity
        $priorPL = '0.00'; // income − expense before the current FY
        $postedRE = '0.00'; // posted balance of the retained earnings account

        foreach ($company->accounts()->orderBy('code')->get() as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            if (in_array($account->type, ['income', 'expense'])) {
                // credit-positive net: income adds, expense (debit-heavy) subtracts
                $earnings = bcadd($earnings, bcsub($s->c, $s->d, 2), 2);
                if ($p = $priorSums->get($account->id)) {
                    $priorPL = bcadd($priorPL, bcsub($p->c, $p->d, 2), 2);
                }

                continue;
            }
            $balance = $account->isDebitNormal() ? bcsub($s->d, $s->c, 2) : bcsub($s->c, $s->d, 2);
            if (bccomp($balance, '0', 2) === 0) {
                continue;
            }
            if ($account->subtype === 'retained_earnings') {
                // Fold posted retained-earnings balance into the Retained Earnings line.
                $postedRE = bcadd($postedRE, $balance, 2);

                continue;
            }
            $sections[$account->type][] = ['account' => $account, 'balance' => $balance];
            $totals[$account->type] = bcadd($totals[$account->type], $balance, 2);
        }

        $retained = bcadd($priorPL, $postedRE, 2);
        $currentYear = bcsub($earnings, $priorPL, 2);
        if (bccomp($retained, '0', 2) !== 0) {
            $sections['equity'][] = ['account' => null, 'label' => 'Retained Earnings', 'balance' => $retained];
            $totals['equity'] = bcadd($totals['equity'], $retained, 2);
        }
        if (bccomp($currentYear, '0', 2) !== 0) {
            $sections['equity'][] = ['account' => null, 'label' => 'Current Year Earnings', 'balance' => $currentYear];
            $totals['equity'] = bcadd($totals['equity'], $currentYear, 2);
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
            ->with(['journalEntry.source'])
            ->where('journal_lines.account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('company_id', $company->id)
                ->whereBetween('entry_date', [$from, $to]))
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $running = $account->balance(to: date('Y-m-d', strtotime($from.' -1 day')));
        $rows = [];
        foreach ($lines as $line) {
            $delta = $account->isDebitNormal()
                ? bcsub($line->debit_base, $line->credit_base, 2)
                : bcsub($line->credit_base, $line->debit_base, 2);
            $running = bcadd($running, $delta, 2);
            $rows[] = ['line' => $line, 'balance' => $running];
        }

        return ['account' => $account, 'rows' => $rows, 'opening' => $account->balance(to: date('Y-m-d', strtotime($from.' -1 day'))), 'closing' => $running];
    }

    /** Products & services activity from posted invoice and bill lines. */
    public function productsAndServices(Company $company, string $from, string $to): array
    {
        $rows = collect();

        $invoiceLines = InvoiceLine::query()
            ->with(['invoice.party', 'item'])
            ->whereHas('invoice', fn ($query) => $query
                ->where('company_id', $company->id)
                ->whereNotIn('status', ['draft', 'void'])
                ->whereBetween('issue_date', [$from, $to]))
            ->get();

        foreach ($invoiceLines as $line) {
            $key = 'item:'.($line->item_id ?: 'invoice-line-'.$line->description);
            $row = $rows->get($key, $this->emptyProductRow($line->item?->name ?? $line->description));
            $row['sales_quantity'] = bcadd($row['sales_quantity'], (string) $line->quantity, 2);
            $row['sales_amount'] = bcadd($row['sales_amount'], (string) $line->line_total, 2);
            $row['details'][] = [
                'date' => $line->invoice->issue_date,
                'type' => 'Invoice',
                'source' => $line->invoice,
                'reference' => $line->invoice->invoice_number,
                'party' => $line->invoice->party?->name,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'amount' => $line->line_total,
            ];
            $rows->put($key, $row);
        }

        $billLines = BillLine::query()
            ->with(['bill.party', 'item'])
            ->whereHas('bill', fn ($query) => $query
                ->where('company_id', $company->id)
                ->whereNotIn('status', ['draft', 'void'])
                ->whereBetween('bill_date', [$from, $to]))
            ->get();

        foreach ($billLines as $line) {
            $key = 'item:'.($line->item_id ?: 'bill-line-'.$line->description);
            $row = $rows->get($key, $this->emptyProductRow($line->item?->name ?? $line->description));
            $row['purchase_quantity'] = bcadd($row['purchase_quantity'], (string) $line->quantity, 2);
            $row['purchase_amount'] = bcadd($row['purchase_amount'], (string) $line->line_total, 2);
            $row['details'][] = [
                'date' => $line->bill->bill_date,
                'type' => 'Bill',
                'source' => $line->bill,
                'reference' => $line->bill->bill_number ?: 'Bill #'.$line->bill->id,
                'party' => $line->bill->party?->name,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'amount' => $line->line_total,
            ];
            $rows->put($key, $row);
        }

        $rows = $rows
            ->map(function (array $row): array {
                $row['net_amount'] = bcsub($row['sales_amount'], $row['purchase_amount'], 2);
                $row['details'] = collect($row['details'])
                    ->sortBy([['date', 'asc'], ['reference', 'asc']])
                    ->values()
                    ->all();

                return $row;
            })
            ->sortByDesc(fn (array $row) => abs((float) $row['net_amount']))
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'sales_quantity' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['sales_quantity'], 2), '0.00'),
                'sales_amount' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['sales_amount'], 2), '0.00'),
                'purchase_quantity' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['purchase_quantity'], 2), '0.00'),
                'purchase_amount' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['purchase_amount'], 2), '0.00'),
                'net_amount' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['net_amount'], 2), '0.00'),
            ],
        ];
    }

    private function emptyProductRow(string $label): array
    {
        return [
            'label' => $label,
            'sales_quantity' => '0.00',
            'sales_amount' => '0.00',
            'purchase_quantity' => '0.00',
            'purchase_amount' => '0.00',
            'net_amount' => '0.00',
            'details' => [],
        ];
    }

    /** Expenses by vendor/source from posted bills and categorized outgoing bank transactions. */
    public function expenses(Company $company, string $from, string $to): array
    {
        $rows = collect();

        $billLines = BillLine::query()
            ->with(['bill.party', 'expenseAccount'])
            ->whereHas('bill', fn ($query) => $query
                ->where('company_id', $company->id)
                ->whereNotIn('status', ['draft', 'void'])
                ->whereBetween('bill_date', [$from, $to]))
            ->get();

        foreach ($billLines as $line) {
            $party = $line->bill->party;
            $key = 'party:'.$party->id;
            $row = $rows->get($key, $this->emptyExpenseRow($party->name, $party));
            $amount = bcadd((string) $line->line_total, (string) $line->tax_amount, 2);
            $row['amount'] = bcadd($row['amount'], $amount, 2);
            $row['details'][] = [
                'date' => $line->bill->bill_date,
                'type' => 'Bill',
                'source' => $line->bill,
                'reference' => $line->bill->bill_number ?: 'Bill #'.$line->bill->id,
                'category' => $line->expenseAccount
                    ? $line->expenseAccount->code.' · '.$line->expenseAccount->name
                    : 'Expense',
                'description' => $line->description,
                'amount' => $amount,
            ];
            $rows->put($key, $row);
        }

        $transactions = BankTransaction::query()
            ->with(['party', 'categoryAccount'])
            ->where('company_id', $company->id)
            ->where('direction', 'out')
            ->whereIn('status', ['categorized', 'reconciled'])
            ->whereNotNull('category_account_id')
            ->whereBetween('txn_date', [$from, $to])
            ->whereHas('categoryAccount', fn ($query) => $query->where('type', 'expense'))
            ->get();

        foreach ($transactions as $transaction) {
            $key = $transaction->party
                ? 'party:'.$transaction->party->id
                : 'unassigned:bank-transactions';
            $row = $rows->get($key, $this->emptyExpenseRow($transaction->party?->name ?? 'Unassigned vendor', $transaction->party));
            $row['amount'] = bcadd($row['amount'], (string) $transaction->amount, 2);
            $row['details'][] = [
                'date' => $transaction->txn_date,
                'type' => 'Bank transaction',
                'source' => $transaction,
                'reference' => 'TXN-'.$transaction->id,
                'category' => $transaction->categoryAccount
                    ? $transaction->categoryAccount->code.' · '.$transaction->categoryAccount->name
                    : 'Expense',
                'description' => $transaction->description,
                'amount' => $transaction->amount,
            ];
            $rows->put($key, $row);
        }

        $rows = $rows
            ->map(function (array $row): array {
                $row['details'] = collect($row['details'])
                    ->sortBy([['date', 'asc'], ['reference', 'asc']])
                    ->values()
                    ->all();

                return $row;
            })
            ->sortByDesc(fn (array $row) => (float) $row['amount'])
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'amount' => $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['amount'], 2), '0.00'),
                'sources' => $rows->reduce(fn ($carry, $row) => $carry + count($row['details']), 0),
            ],
        ];
    }

    private function emptyExpenseRow(string $label, mixed $party): array
    {
        return [
            'label' => $label,
            'party' => $party,
            'amount' => '0.00',
            'details' => [],
        ];
    }

    /** SST-02 helper: output tax collected per tax code for a taxable period. */
    public function sstOutputSummary(Company $company, string $from, string $to): array
    {
        $rows = InvoiceLine::query()
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

    /** Aged payables, mirroring aged receivables buckets. Only bills dated on or before $asOf. */
    public function agedPayables(Company $company, ?string $asOf = null): Collection
    {
        $asOf = Carbon::parse($asOf ?: today());

        return $company->bills()
            ->with('party')
            ->whereIn('status', ['approved', 'partial'])
            ->whereDate('bill_date', '<=', $asOf)
            ->get()
            ->groupBy(fn ($bill) => $bill->party->name)
            ->map(function (Collection $bills) use ($asOf) {
                $buckets = [
                    'party' => $bills->first()->party,
                    'current' => '0.00',
                    'b30' => '0.00',
                    'b60' => '0.00',
                    'b90' => '0.00',
                    'total' => '0.00',
                ];
                foreach ($bills as $bill) {
                    $due = $bill->due_date ?? $bill->bill_date;
                    $days = (int) $due->diffInDays($asOf, false);
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
