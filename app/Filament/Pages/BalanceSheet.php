<?php

namespace App\Filament\Pages;

use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class BalanceSheet extends Page
{
    protected string $view = 'filament.pages.balance-sheet';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->balanceSheet(Filament::getTenant(), $this->asOf);
    }

    public function downloadCsv()
    {
        $r = $this->getReport();

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Balance Sheet', 'As of '.$this->asOf]);
            foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $type => $label) {
                fputcsv($out, [$label]);
                foreach ($r['sections'][$type] as $row) {
                    $name = ($row['account'] ?? null) ? $row['account']->code.' '.$row['account']->name : $row['label'];
                    fputcsv($out, [$name, $row['balance']]);
                }
                fputcsv($out, ['Total '.strtolower($label), $r['totals'][$type]]);
            }
            fputcsv($out, ['Liabilities + equity', $r['liabilities_plus_equity']]);
            fclose($out);
        }, 'balance-sheet-'.$this->asOf.'.csv');
    }
}
