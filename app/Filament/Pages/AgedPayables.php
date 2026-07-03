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

    public function getRows(): Collection
    {
        return app(ReportService::class)->agedPayables(Filament::getTenant());
    }
}
