<?php

namespace App\Filament\Resources\Estimates\Pages;

use App\Filament\Resources\Estimates\EstimateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEstimates extends ListRecords
{
    protected static string $resource = EstimateResource::class;

    /** Estimates awaiting a customer decision. */
    private const ACTIVE_STATUSES = ['sent', 'accepted'];

    /** Show just "Estimates" instead of "Estimates > List". */
    public function getBreadcrumbs(): array
    {
        return [EstimateResource::getBreadcrumb()];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $counts = EstimateResource::getEloquentQuery()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', self::ACTIVE_STATUSES))
                ->badge(collect(self::ACTIVE_STATUSES)->sum(fn ($s) => $counts[$s] ?? 0)),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge($counts['draft'] ?? 0),
            'all' => Tab::make('All')->badge($counts->sum()),
        ];
    }
}
