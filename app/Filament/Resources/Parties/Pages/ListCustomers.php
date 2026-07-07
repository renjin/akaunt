<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListCustomers extends ListRecords
{
    protected static string $resource = PartyResource::class;

    public function getTitle(): string
    {
        return 'Customers';
    }

    public function getBreadcrumbs(): array
    {
        return [PartyResource::getUrl('customers') => 'Customers'];
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()->whereIn('role', ['customer', 'both']);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add a customer')
                ->url(PartyResource::getUrl('create', ['role' => 'customer'])),
        ];
    }
}
