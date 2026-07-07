<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Bill;
use App\Models\Invoice;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;

class ProductsAndServices extends Page
{
    protected string $view = 'filament.pages.products-and-services';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Products & Services';

    protected static ?int $navigationSort = 9;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = request()->query('from') ?: today()->startOfYear()->toDateString();
        $this->to = request()->query('to') ?: today()->toDateString();
    }

    public function getReport(): array
    {
        return app(ReportService::class)->productsAndServices(Filament::getTenant(), $this->from, $this->to);
    }

    public function sourceUrl(?Model $source): ?string
    {
        if ($source instanceof Invoice) {
            return InvoiceResource::getUrl('view', ['record' => $source]);
        }

        if ($source instanceof Bill) {
            return BillResource::getUrl('view', ['record' => $source]);
        }

        return null;
    }

    public function downloadCsv()
    {
        $report = $this->getReport();

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Products & Services', $this->from.' to '.$this->to]);
            fputcsv($out, ['Product or service', 'Sales qty', 'Sales amount', 'Purchase qty', 'Purchase amount', 'Net amount']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['label'],
                    $row['sales_quantity'],
                    $row['sales_amount'],
                    $row['purchase_quantity'],
                    $row['purchase_amount'],
                    $row['net_amount'],
                ]);

                foreach ($row['details'] as $detail) {
                    fputcsv($out, [
                        '  '.$detail['date']->toDateString(),
                        $detail['type'],
                        $detail['reference'],
                        $detail['party'],
                        $detail['quantity'],
                        $detail['amount'],
                        $detail['description'],
                    ]);
                }
            }

            fputcsv($out, ['Totals', $report['totals']['sales_quantity'], $report['totals']['sales_amount'], $report['totals']['purchase_quantity'], $report['totals']['purchase_amount'], $report['totals']['net_amount']]);
            fclose($out);
        }, 'products-and-services-'.$this->from.'-to-'.$this->to.'.csv');
    }
}
