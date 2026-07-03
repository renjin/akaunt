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

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->balanceSheet(Filament::getTenant(), $this->asOf);
    }
}
