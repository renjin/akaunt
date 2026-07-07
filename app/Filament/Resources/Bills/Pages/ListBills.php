<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Bills\Widgets\BillStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBills extends ListRecords
{
    protected static string $resource = BillResource::class;

    private const UNPAID_STATUSES = ['approved', 'partial'];

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            BillStats::class,
        ];
    }

    public function getTabs(): array
    {
        $counts = BillResource::getEloquentQuery()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $overdue = fn (Builder $query) => $query
            ->whereIn('status', self::UNPAID_STATUSES)
            ->whereDate('due_date', '<', today())
            ->whereColumn('amount_paid', '<', 'total');

        return [
            'all' => Tab::make('All')->badge($counts->sum()),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge($counts['draft'] ?? 0),
            'unpaid' => Tab::make('Unpaid')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', self::UNPAID_STATUSES))
                ->badge(collect(self::UNPAID_STATUSES)->sum(fn ($s) => $counts[$s] ?? 0)),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing($overdue)
                ->badge($overdue(BillResource::getEloquentQuery())->count())
                ->badgeColor('danger'),
            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid'))
                ->badge($counts['paid'] ?? 0),
            'void' => Tab::make('Void')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'void'))
                ->badge($counts['void'] ?? 0),
        ];
    }
}
