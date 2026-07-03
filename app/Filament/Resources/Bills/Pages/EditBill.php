<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillActions;
use App\Filament\Resources\Bills\BillResource;
use App\Services\BillService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBill extends EditRecord
{
    protected static string $resource = BillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...BillActions::make(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status === 'draft') {
            app(BillService::class)->calculateTotals($this->record);
        }
    }
}
