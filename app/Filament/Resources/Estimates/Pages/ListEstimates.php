<?php

namespace App\Filament\Resources\Estimates\Pages;

use App\Filament\Resources\Estimates\EstimateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEstimates extends ListRecords
{
    protected static string $resource = EstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
