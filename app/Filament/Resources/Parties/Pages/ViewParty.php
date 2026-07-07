<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Party;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewParty extends ViewRecord
{
    protected static string $resource = PartyResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit profile'),
            Action::make('createInvoice')
                ->label('Create invoice')
                ->icon('heroicon-o-document-plus')
                ->url(InvoiceResource::getUrl('create'))
                ->visible(fn (Party $record) => $record->isCustomer()),
            Action::make('createBill')
                ->label('Create bill')
                ->icon('heroicon-o-document-plus')
                ->url(BillResource::getUrl('create'))
                ->visible(fn (Party $record) => $record->isVendor()),
        ];
    }
}
