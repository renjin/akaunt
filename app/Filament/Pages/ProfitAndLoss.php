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

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->profitAndLoss(Filament::getTenant(), $this->from, $this->to);
    }
}
