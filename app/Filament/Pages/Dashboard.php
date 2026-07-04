<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -10;

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getViewData(): array
    {
        $company = Filament::getTenant();
        $today = today();
        $start = $today->copy()->subMonths(11)->startOfMonth();
        $report = app(ReportService::class)->profitAndLoss($company, $start->toDateString(), $today->toDateString());

        $openInvoices = $company->invoices()
            ->with('party')
            ->whereIn('status', ['approved', 'sent', 'partial'])
            ->orderBy('due_date')
            ->get();

        $openBills = $company->bills()
            ->with('party')
            ->whereIn('status', ['approved', 'partial'])
            ->orderBy('due_date')
            ->get();

        $bankTotals = $company->bankTransactions()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as inflow")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as outflow")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'unmatched') as unmatched")
            ->first();

        return [
            'company' => $company,
            'actions' => $this->quickActions(),
            'openInvoices' => $openInvoices->take(3),
            'openBills' => $openBills->take(3),
            'summary' => [
                'overdue_invoices' => $this->sumBalances($openInvoices->filter(fn ($invoice) => $invoice->due_date?->isPast())),
                'due_soon' => $this->sumBalances($openInvoices->filter(fn ($invoice) => $invoice->due_date?->between($today, $today->copy()->addDays(30)))),
                'overdue_bills' => $this->sumBalances($openBills->filter(fn ($bill) => $bill->due_date?->isPast())),
                'income' => $report['totals']['income'],
                'expenses' => bcadd($report['totals']['expense'], $report['totals']['cogs'], 2),
                'net_profit' => $report['net_profit'],
                'bank_inflow' => number_format((float) ($bankTotals->inflow ?? 0), 2, '.', ''),
                'bank_outflow' => number_format((float) ($bankTotals->outflow ?? 0), 2, '.', ''),
                'unmatched' => (int) ($bankTotals->unmatched ?? 0),
            ],
            'cashFlowBars' => $this->cashFlowBars(),
            'profitBars' => $this->profitBars($report),
        ];
    }

    private function quickActions(): array
    {
        return [
            ['label' => 'Create invoice', 'href' => InvoiceResource::getUrl('create'), 'icon' => 'receipt', 'tone' => 'blue'],
            ['label' => 'Record payment', 'href' => InvoiceResource::getUrl(), 'icon' => 'payment', 'tone' => 'green'],
            ['label' => 'Add bill', 'href' => BillResource::getUrl('create'), 'icon' => 'bill', 'tone' => 'lavender'],
            ['label' => 'Add transaction', 'href' => BankTransactionResource::getUrl('create'), 'icon' => 'transfer', 'tone' => 'yellow'],
        ];
    }

    private function sumBalances(Collection $records): string
    {
        return $records->reduce(
            fn (string $carry, $record): string => bcadd($carry, $record->balance_due, 2),
            '0.00',
        );
    }

    private function cashFlowBars(): array
    {
        $company = Filament::getTenant();
        $months = collect(range(5, 0))->map(fn (int $offset) => today()->subMonths($offset)->startOfMonth());

        return $months->map(function ($month) use ($company): array {
            $rows = $company->bankTransactions()
                ->whereBetween('txn_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as inflow")
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as outflow")
                ->first();

            return [
                'label' => $month->format('M'),
                'inflow' => (float) ($rows->inflow ?? 0),
                'outflow' => (float) ($rows->outflow ?? 0),
            ];
        })->all();
    }

    private function profitBars(array $report): array
    {
        $income = (float) $report['totals']['income'];
        $expenses = (float) bcadd($report['totals']['expense'], $report['totals']['cogs'], 2);
        $max = max($income, $expenses, 1);

        return [
            ['label' => 'Income', 'value' => $income, 'width' => max(6, ($income / $max) * 100), 'tone' => 'income'],
            ['label' => 'Expenses', 'value' => $expenses, 'width' => max(6, ($expenses / $max) * 100), 'tone' => 'expense'],
            ['label' => 'Net profit', 'value' => (float) $report['net_profit'], 'width' => max(6, min(100, abs((float) $report['net_profit']) / $max * 100)), 'tone' => 'profit'],
        ];
    }
}
