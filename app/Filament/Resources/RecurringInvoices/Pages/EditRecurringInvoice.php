<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecurringInvoice extends EditRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
