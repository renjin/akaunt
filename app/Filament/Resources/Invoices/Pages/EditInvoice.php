<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...InvoiceActions::make(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status === 'draft') {
            app(InvoiceService::class)->calculateTotals($this->record);
        }
    }
}
