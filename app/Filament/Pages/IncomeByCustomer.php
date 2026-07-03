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

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
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
                'count' => $invoices->count(),
                'income' => $invoices->reduce(fn ($c, $i) => bcadd($c, $i->subtotal, 2), '0.00'),
                'paid' => $invoices->reduce(fn ($c, $i) => bcadd($c, $i->amount_paid, 2), '0.00'),
            ])
            ->sortByDesc(fn ($row) => (float) $row['income']);
    }
}
