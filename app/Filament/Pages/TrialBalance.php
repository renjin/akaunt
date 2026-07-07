<?php

namespace App\Filament\Pages;

use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class TrialBalance extends Page
{
    protected string $view = 'filament.pages.trial-balance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->trialBalance(Filament::getTenant(), $this->asOf);
    }

    public function downloadCsv()
    {
        $r = $this->getReport();

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Trial Balance', 'As of '.$this->asOf]);
            fputcsv($out, ['Account', 'Debit', 'Credit']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, [$row['account']->code.' '.$row['account']->name, $row['debit'], $row['credit']]);
            }
            fputcsv($out, ['Total', $r['total_debit'], $r['total_credit']]);
            fclose($out);
        }, 'trial-balance-'.$this->asOf.'.csv');
    }
}
