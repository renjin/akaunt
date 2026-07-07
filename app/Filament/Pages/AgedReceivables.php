<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AgedReceivables extends Page
{
    protected string $view = 'filament.pages.aged-receivables';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 5;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    /** @return Collection<string, array> rows keyed by customer */
    public function getRows(): Collection
    {
        $asOf = Carbon::parse($this->asOf ?: today());

        return Filament::getTenant()->invoices()
            ->with('party')
            ->whereIn('status', ['approved', 'sent', 'partial'])
            ->whereDate('issue_date', '<=', $asOf)
            ->get()
            ->groupBy(fn ($inv) => $inv->party->name)
            ->map(function (Collection $invoices) use ($asOf) {
                $buckets = [
                    'party' => $invoices->first()->party,
                    'current' => '0.00',
                    'b30' => '0.00',
                    'b60' => '0.00',
                    'b90' => '0.00',
                    'total' => '0.00',
                ];
                foreach ($invoices as $inv) {
                    $due = $inv->due_date ?? $inv->issue_date;
                    $days = (int) $due->diffInDays($asOf, false);
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

    /** Grand totals across all customers, one sum per aging bucket. */
    public function getTotals(Collection $rows): array
    {
        $totals = ['current' => '0.00', 'b30' => '0.00', 'b60' => '0.00', 'b90' => '0.00', 'total' => '0.00'];
        foreach ($rows as $buckets) {
            foreach ($totals as $key => $sum) {
                $totals[$key] = bcadd($sum, $buckets[$key], 2);
            }
        }

        return $totals;
    }

    public function downloadCsv()
    {
        $rows = $this->getRows();
        $totals = $this->getTotals($rows);

        return response()->streamDownload(function () use ($rows, $totals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Aged Receivables', 'As of '.$this->asOf]);
            fputcsv($out, ['Customer', 'Current', '1-30 days', '31-60 days', '60+ days', 'Total due']);
            foreach ($rows as $customer => $b) {
                fputcsv($out, [$customer, $b['current'], $b['b30'], $b['b60'], $b['b90'], $b['total']]);
            }
            fputcsv($out, ['Total', $totals['current'], $totals['b30'], $totals['b60'], $totals['b90'], $totals['total']]);
            fclose($out);
        }, 'aged-receivables-'.$this->asOf.'.csv');
    }
}
