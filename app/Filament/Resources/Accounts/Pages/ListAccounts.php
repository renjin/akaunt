<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add a New Account'),
        ];
    }

    public function getTabs(): array
    {
        // One tab per account type (Wave layout). Each scopes the query by type
        // and shows a live per-tenant badge count. Type grouping is left OFF
        // because a single type is already active; the table groups by subtype.
        $types = [
            'assets' => ['label' => 'Assets', 'type' => 'asset'],
            'liabilities' => ['label' => 'Liabilities', 'type' => 'liability'],
            'income' => ['label' => 'Income', 'type' => 'income'],
            'expenses' => ['label' => 'Expenses', 'type' => 'expense'],
            'equity' => ['label' => 'Equity', 'type' => 'equity'],
        ];

        // getEloquentQuery() already applies the current tenant scope.
        $counts = AccountResource::getEloquentQuery()
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type');

        $tabs = [
            'all' => Tab::make('All')->badge($counts->sum()),
        ];

        foreach ($types as $key => $config) {
            $tabs[$key] = Tab::make($config['label'])
                ->badge($counts[$config['type']] ?? 0)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $config['type']));
        }

        return $tabs;
    }
}
