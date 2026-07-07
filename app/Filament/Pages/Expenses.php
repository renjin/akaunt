<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\Party;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;

class Expenses extends Page
{
    protected string $view = 'filament.pages.expenses';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Expenses';

    protected static ?int $navigationSort = 10;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = request()->query('from') ?: request()->query('start') ?: today()->startOfYear()->toDateString();
        $this->to = request()->query('to') ?: request()->query('end') ?: today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->expenses(Filament::getTenant(), $this->from, $this->to);
    }

    public function partyUrl(?Party $party): ?string
    {
        return $party ? PartyResource::getUrl('view', ['record' => $party]) : null;
    }

    public function sourceUrl(?Model $source): ?string
    {
        if ($source instanceof Bill) {
            return BillResource::getUrl('view', ['record' => $source]);
        }

        if ($source instanceof BankTransaction) {
            return BankTransactionResource::getUrl('edit', ['record' => $source]);
        }

        return null;
    }

    public function downloadCsv()
    {
        $report = $this->getReport();

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Expenses', $this->from.' to '.$this->to]);
            fputcsv($out, ['Vendor', 'Amount']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [$row['label'], $row['amount']]);
                foreach ($row['details'] as $detail) {
                    fputcsv($out, [
                        '  '.$detail['date']->toDateString(),
                        $detail['type'],
                        $detail['reference'],
                        $detail['category'],
                        $detail['description'],
                        $detail['amount'],
                    ]);
                }
            }

            fputcsv($out, ['Total', $report['totals']['amount']]);
            fclose($out);
        }, 'expenses-'.$this->from.'-to-'.$this->to.'.csv');
    }
}
