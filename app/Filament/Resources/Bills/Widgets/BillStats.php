<?php

namespace App\Filament\Resources\Bills\Widgets;

use App\Filament\Resources\Bills\BillResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BillStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $today = today()->toDateString();
        $horizon = today()->addDays(30)->toDateString();

        $row = BillResource::getEloquentQuery()
            ->whereIn('status', ['approved', 'partial'])
            ->selectRaw(
                'COALESCE(SUM(total - amount_paid), 0) AS total_unpaid,'
                .' COALESCE(SUM(CASE WHEN due_date < ? THEN total - amount_paid ELSE 0 END), 0) AS overdue,'
                .' COALESCE(SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN total - amount_paid ELSE 0 END), 0) AS due_soon',
                [$today, $today, $horizon],
            )
            ->first();

        $money = fn ($value) => 'MYR '.number_format((float) $value, 2);

        return [
            Stat::make('Overdue', $money($row?->overdue ?? 0))
                ->description('Past due, still owed')
                ->color('danger'),
            Stat::make('Due within 30 days', $money($row?->due_soon ?? 0))
                ->description('Coming due soon')
                ->color('warning'),
            Stat::make('Total unpaid', $money($row?->total_unpaid ?? 0))
                ->description('All open bills'),
        ];
    }
}
