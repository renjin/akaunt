<?php

namespace App\Filament\Resources\Estimates\Pages;

use App\Filament\Resources\Estimates\EstimateResource;
use App\Services\EstimateService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateEstimate extends CreateRecord
{
    protected static string $resource = EstimateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['estimate_number'] = app(EstimateService::class)->nextNumber(Filament::getTenant());
        $data['currency'] = Filament::getTenant()->base_currency ?? 'MYR';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(EstimateService::class)->calculateTotals($this->record);
    }
}
