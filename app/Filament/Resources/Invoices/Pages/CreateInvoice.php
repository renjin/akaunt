<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Concerns\PreviewsInvoice;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateInvoice extends CreateRecord
{
    use PreviewsInvoice;

    protected static string $resource = InvoiceResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['invoice_number'] ?? null)) {
            $data['invoice_number'] = app(InvoiceService::class)->nextNumber(Filament::getTenant());
        }
        $data['currency'] = $data['currency'] ?? Filament::getTenant()->base_currency ?? 'MYR';
        if ($data['currency'] === 'MYR') {
            $data['fx_rate'] = 1;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(InvoiceService::class)->calculateTotals($this->record);
    }
}
