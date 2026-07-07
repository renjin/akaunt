<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Widgets\InvoiceStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    private const UNPAID_STATUSES = ['approved', 'sent', 'partial'];

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InvoiceStats::class,
        ];
    }

    public function getTabs(): array
    {
        $counts = InvoiceResource::getEloquentQuery()
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
                ->badge($overdue(InvoiceResource::getEloquentQuery())->count())
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
