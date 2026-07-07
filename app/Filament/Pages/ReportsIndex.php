<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ReportsIndex extends Page
{
    protected string $view = 'filament.pages.reports-index';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 0;

    /** @return array<string, array<int, array{title: string, description: string, url: string, icon: string}>> */
    public function getReportGroups(): array
    {
        return [
            'Financial statements' => [
                [
                    'title' => 'Profit & Loss',
                    'description' => 'Income, expenses and net profit over a period',
                    'url' => ProfitAndLoss::getUrl(),
                    'icon' => 'heroicon-o-chart-bar',
                ],
                [
                    'title' => 'Balance Sheet',
                    'description' => 'Assets, liabilities and equity as of a date',
                    'url' => BalanceSheet::getUrl(),
                    'icon' => 'heroicon-o-building-library',
                ],
                [
                    'title' => 'Trial Balance',
                    'description' => "Every account's debit and credit balance",
                    'url' => TrialBalance::getUrl(),
                    'icon' => 'heroicon-o-scale',
                ],
                [
                    'title' => 'Account Transactions',
                    'description' => 'General ledger details with links to source documents',
                    'url' => GeneralLedger::getUrl(),
                    'icon' => 'heroicon-o-book-open',
                ],
                [
                    'title' => 'Products & Services',
                    'description' => 'Sales and purchase activity by item, with source details',
                    'url' => ProductsAndServices::getUrl(),
                    'icon' => 'heroicon-o-square-3-stack-3d',
                ],
            ],
            'Receivables & payables' => [
                [
                    'title' => 'Aged Receivables',
                    'description' => 'Unpaid customer invoices by age',
                    'url' => AgedReceivables::getUrl(),
                    'icon' => 'heroicon-o-inbox-arrow-down',
                ],
                [
                    'title' => 'Aged Payables',
                    'description' => 'Unpaid vendor bills by age',
                    'url' => AgedPayables::getUrl(),
                    'icon' => 'heroicon-o-clock',
                ],
                [
                    'title' => 'Customer Statement',
                    'description' => 'Activity statement for one customer',
                    'url' => CustomerStatement::getUrl(),
                    'icon' => 'heroicon-o-document-text',
                ],
                [
                    'title' => 'Income by Customer',
                    'description' => 'Revenue contribution per customer',
                    'url' => IncomeByCustomer::getUrl(),
                    'icon' => 'heroicon-o-users',
                ],
                [
                    'title' => 'Expenses',
                    'description' => 'Vendor and bank-spend detail for posted expenses',
                    'url' => Expenses::getUrl(),
                    'icon' => 'heroicon-o-banknotes',
                ],
            ],
            'Tax' => [
                [
                    'title' => 'SST-02 Summary',
                    'description' => 'Output tax for your SST return',
                    'url' => SstReturn::getUrl(),
                    'icon' => 'heroicon-o-receipt-percent',
                ],
            ],
        ];
    }
}
