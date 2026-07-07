<?php

namespace App\Filament\Pages;

use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class AgedPayables extends Page
{
    protected string $view = 'filament.pages.aged-payables';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 6;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function getRows(): Collection
    {
        return app(ReportService::class)->agedPayables(Filament::getTenant(), $this->asOf ?: null);
    }

    /** Grand totals across all vendors, one sum per aging bucket. */
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
            fputcsv($out, ['Aged Payables', 'As of '.$this->asOf]);
            fputcsv($out, ['Vendor', 'Current', '1-30 days', '31-60 days', '60+ days', 'Total due']);
            foreach ($rows as $vendor => $b) {
                fputcsv($out, [$vendor, $b['current'], $b['b30'], $b['b60'], $b['b90'], $b['total']]);
            }
            fputcsv($out, ['Total', $totals['current'], $totals['b30'], $totals['b60'], $totals['b90'], $totals['total']]);
            fclose($out);
        }, 'aged-payables-'.$this->asOf.'.csv');
    }
}
