<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Services\BillService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBill extends CreateRecord
{
    protected static string $resource = BillResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['currency'] = Filament::getTenant()->base_currency ?? 'MYR';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(BillService::class)->calculateTotals($this->record);
    }
}
