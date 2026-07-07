<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillActions;
use App\Filament\Resources\Bills\BillResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBill extends ViewRecord
{
    protected static string $resource = BillResource::class;

    public function getTitle(): string
    {
        return $this->record->bill_number
            ? "Bill #{$this->record->bill_number}"
            : 'Bill';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit bill'),
            ...BillActions::make(),
        ];
    }
}
