<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;

class GeneralLedger extends Page
{
    protected string $view = 'filament.pages.general-ledger';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Account Transactions';

    protected static ?string $navigationLabel = 'Account Transactions';

    protected static ?int $navigationSort = 4;

    public ?int $accountId = null;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        // Accept drill-down links from other reports: ?account=&from=&to=
        $this->accountId = ((int) request()->query('account')) ?: null;
        $this->from = request()->query('from') ?: today()->startOfYear()->toDateString();
        $this->to = request()->query('to') ?: today()->toDateString();
    }

    public function getAccounts()
    {
        return Filament::getTenant()->accounts()->orderBy('code')->get();
    }

    public function getReport(): ?array
    {
        if (! $this->accountId) {
            return null;
        }
        $account = Account::find($this->accountId);
        if (! $account || $account->company_id !== Filament::getTenant()->id) {
            return null;
        }

        return app(ReportService::class)->generalLedger(Filament::getTenant(), $account, $this->from, $this->to);
    }

    public function downloadCsv()
    {
        $r = $this->getReport();
        if (! $r) {
            return null;
        }

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account Transactions', $this->from.' to '.$this->to]);
            fputcsv($out, ['Date', 'Description', 'Source', 'Ref', 'Debit', 'Credit', 'Balance']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, [
                    $row['line']->journalEntry->entry_date,
                    $row['line']->journalEntry->description ?? $row['line']->memo,
                    $this->sourceLabel($row['line']->journalEntry->source),
                    $row['line']->journalEntry->reference,
                    $row['line']->debit_base,
                    $row['line']->credit_base,
                    $row['balance'],
                ]);
            }
            fputcsv($out, ['Closing balance', '', '', '', '', '', $r['closing']]);
            fclose($out);
        }, 'account-transactions-'.$this->from.'-to-'.$this->to.'.csv');
    }

    public function sourceLabel(?Model $source): string
    {
        return match (true) {
            $source instanceof Invoice => $source->invoice_number,
            $source instanceof Bill => $source->bill_number ?: 'Bill #'.$source->id,
            $source instanceof Payment => ucfirst($source->payment_type).' payment'.($source->reference ? ' · '.$source->reference : ''),
            $source instanceof BankTransaction => $source->description ?: 'Bank transaction #'.$source->id,
            default => 'Manual journal',
        };
    }

    public function sourceUrl(?Model $source): ?string
    {
        if ($source instanceof Invoice) {
            return InvoiceResource::getUrl('view', ['record' => $source]);
        }

        if ($source instanceof Bill) {
            return BillResource::getUrl('view', ['record' => $source]);
        }

        if ($source instanceof BankTransaction) {
            return BankTransactionResource::getUrl('edit', ['record' => $source]);
        }

        if ($source instanceof Payment) {
            $allocation = $source->allocations()->with('allocatable')->first();

            return $this->sourceUrl($allocation?->allocatable);
        }

        return null;
    }
}
