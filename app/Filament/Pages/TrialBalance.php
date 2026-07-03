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

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->trialBalance(Filament::getTenant(), $this->asOf);
    }
}
