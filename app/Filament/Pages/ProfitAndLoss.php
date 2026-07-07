<?php

namespace App\Filament\Pages;

use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class ProfitAndLoss extends Page
{
    protected string $view = 'filament.pages.profit-and-loss';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Profit & Loss';

    protected static ?int $navigationSort = 1;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = request()->query('from') ?: request()->query('start') ?: today()->startOfYear()->toDateString();
        $this->to = request()->query('to') ?: request()->query('end') ?: today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->profitAndLoss(Filament::getTenant(), $this->from, $this->to);
    }

    public function downloadCsv()
    {
        $r = $this->getReport();

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Profit & Loss', $this->from.' to '.$this->to]);
            foreach ([['income', 'Income'], ['cogs', 'Cost of sales'], ['expense', 'Expenses']] as [$key, $label]) {
                if (! count($r['sections'][$key])) {
                    continue;
                }
                fputcsv($out, [$label]);
                foreach ($r['sections'][$key] as $row) {
                    fputcsv($out, [$row['account']->code.' '.$row['account']->name, $row['balance']]);
                }
                fputcsv($out, ['Total '.strtolower($label), $r['totals'][$key]]);
            }
            fputcsv($out, ['Gross profit', $r['gross_profit']]);
            fputcsv($out, ['Net profit', $r['net_profit']]);
            fclose($out);
        }, 'profit-and-loss-'.$this->from.'-to-'.$this->to.'.csv');
    }
}
