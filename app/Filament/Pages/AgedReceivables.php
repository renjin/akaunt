<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class AgedReceivables extends Page
{
    protected string $view = 'filament.pages.aged-receivables';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    /** @return Collection<string, array> rows keyed by customer */
    public function getRows(): Collection
    {
        $today = today();

        return Filament::getTenant()->invoices()
            ->with('party')
            ->whereIn('status', ['approved', 'sent', 'partial'])
            ->get()
            ->groupBy(fn ($inv) => $inv->party->name)
            ->map(function (Collection $invoices) use ($today) {
                $buckets = ['current' => '0.00', 'b30' => '0.00', 'b60' => '0.00', 'b90' => '0.00', 'total' => '0.00'];
                foreach ($invoices as $inv) {
                    $due = $inv->due_date ?? $inv->issue_date;
                    $days = (int) $due->diffInDays($today, false);
                    $bucket = match (true) {
                        $days <= 0 => 'current',
                        $days <= 30 => 'b30',
                        $days <= 60 => 'b60',
                        default => 'b90',
                    };
                    $buckets[$bucket] = bcadd($buckets[$bucket], $inv->balance_due, 2);
                    $buckets['total'] = bcadd($buckets['total'], $inv->balance_due, 2);
                }

                return $buckets;
            })
            ->sortKeys();
    }
}
