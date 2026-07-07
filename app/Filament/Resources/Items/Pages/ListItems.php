<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $counts = ItemResource::getEloquentQuery()
            ->selectRaw('kind, count(*) as c')
            ->groupBy('kind')
            ->pluck('c', 'kind');

        return [
            'all' => Tab::make('All')->badge($counts->sum()),
            'sales' => Tab::make('Sales')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', 'sales'))
                ->badge($counts['sales'] ?? 0)
                ->badgeColor('info'),
            'purchase' => Tab::make('Purchase')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', 'purchase'))
                ->badge($counts['purchase'] ?? 0)
                ->badgeColor('warning'),
        ];
    }
}
