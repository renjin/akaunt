<?php

namespace App\Filament\Pages;

use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class SstReturn extends Page
{
    protected string $view = 'filament.pages.sst-return';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'SST-02 Summary';

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        // SST taxable periods are bi-monthly; default to the last two months
        $this->from = today()->subMonths(2)->startOfMonth()->toDateString();
        $this->to = today()->subMonth()->endOfMonth()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->sstOutputSummary(Filament::getTenant(), $this->from, $this->to);
    }
}
