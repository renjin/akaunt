<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['invoice_number'] = app(InvoiceService::class)->nextNumber(Filament::getTenant());
        $data['currency'] = Filament::getTenant()->base_currency ?? 'MYR';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(InvoiceService::class)->calculateTotals($this->record);
    }
}
