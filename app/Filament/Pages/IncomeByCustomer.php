<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class IncomeByCustomer extends Page
{
    protected string $view = 'filament.pages.income-by-customer';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 8;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = request()->query('from') ?: request()->query('start') ?: today()->startOfYear()->toDateString();
        $this->to = request()->query('to') ?: request()->query('end') ?: today()->toDateString();
    }

    public function getRows(): Collection
    {
        return Filament::getTenant()->invoices()
            ->with('party')
            ->whereNotIn('status', ['draft', 'void'])
            ->whereBetween('issue_date', [$this->from, $this->to])
            ->get()
            ->groupBy(fn ($inv) => $inv->party->name)
            ->map(fn (Collection $invoices) => [
                'party' => $invoices->first()->party,
                'count' => $invoices->count(),
                'income' => $invoices->reduce(fn ($c, $i) => bcadd($c, $i->subtotal, 2), '0.00'),
                'paid' => $invoices->reduce(fn ($c, $i) => bcadd($c, $i->amount_paid, 2), '0.00'),
            ])
            ->sortByDesc(fn ($row) => (float) $row['income']);
    }

    public function downloadCsv()
    {
        $rows = $this->getRows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Income by Customer', $this->from.' to '.$this->to]);
            fputcsv($out, ['Customer', 'Invoices', 'Income (excl. SST)', 'Collected']);
            foreach ($rows as $customer => $r) {
                fputcsv($out, [$customer, $r['count'], $r['income'], $r['paid']]);
            }
            fclose($out);
        }, 'income-by-customer-'.$this->from.'-to-'.$this->to.'.csv');
    }
}
