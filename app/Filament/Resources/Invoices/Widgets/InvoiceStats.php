<?php

namespace App\Filament\Resources\Invoices\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class InvoiceStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    private const UNPAID_STATUSES = ['approved', 'sent', 'partial'];

    protected function getStats(): array
    {
        $overdue = $this->outstanding(fn (Builder $q) => $q->whereDate('due_date', '<', today()));

        $dueSoon = $this->outstanding(fn (Builder $q) => $q
            ->whereDate('due_date', '>=', today())
            ->whereDate('due_date', '<=', today()->addDays(30)));

        return [
            Stat::make('Overdue', $this->money($overdue->amount))
                ->description("Across {$overdue->invoices} unpaid ".str('invoice')->plural($overdue->invoices))
                ->color($overdue->invoices > 0 ? 'danger' : 'gray'),
            Stat::make('Due within next 30 days', $this->money($dueSoon->amount))
                ->description("{$dueSoon->invoices} ".str('invoice')->plural($dueSoon->invoices).' coming due')
                ->color('info'),
            Stat::make('Average time to get paid', $this->averageDaysToPay())
                ->description('Invoices paid in the last 12 months')
                ->color('success'),
        ];
    }

    /** Sum + count of balances due for unpaid invoices matching $constraint. */
    private function outstanding(callable $constraint): object
    {
        return $constraint(InvoiceResource::getEloquentQuery())
            ->whereIn('status', self::UNPAID_STATUSES)
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS amount, COUNT(*) AS invoices')
            ->first();
    }

    /** Mean days from issue to final payment, for invoices fully paid in the last 12 months. */
    private function averageDaysToPay(): string
    {
        $cutoff = today()->subMonths(12);

        $paid = InvoiceResource::getEloquentQuery()
            ->where('status', 'paid')
            ->whereNotNull('issue_date')
            ->whereHas('allocations.payment', fn (Builder $q) => $q->whereDate('payment_date', '>=', $cutoff))
            ->with('allocations.payment')
            ->get();

        $days = $paid
            ->map(function (Invoice $invoice) use ($cutoff) {
                $finalPayment = $invoice->allocations
                    ->map(fn ($allocation) => $allocation->payment?->payment_date)
                    ->filter()
                    ->max();

                if (! $finalPayment || $finalPayment->lt($cutoff)) {
                    return null;
                }

                return max(0, (int) $invoice->issue_date->diffInDays($finalPayment));
            })
            ->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? '—' : number_format($days->avg(), 1).' days';
    }

    private function money(string|float|null $amount): string
    {
        return 'MYR '.number_format((float) $amount, 2);
    }
}
