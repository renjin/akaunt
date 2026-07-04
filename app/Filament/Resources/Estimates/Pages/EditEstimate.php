<?php

namespace App\Filament\Resources\Estimates\Pages;

use App\Filament\Resources\Estimates\EstimateActions;
use App\Filament\Resources\Estimates\EstimateResource;
use App\Services\EstimateService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEstimate extends EditRecord
{
    protected static string $resource = EstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...EstimateActions::make(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status === 'draft') {
            app(EstimateService::class)->calculateTotals($this->record);
        }
    }
}
