<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Models\Account;
use App\Services\BankTransactionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    Select::make('bank_account_id')
                        ->label('Into bank account')
                        ->options(fn () => Filament::getTenant()->accounts()
                            ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                        ->required(),
                    FileUpload::make('file')
                        ->label('CSV file (date, description, amount — negative = money out)')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $csv = $data['file']->get();
                    [$imported, $skipped] = app(BankTransactionService::class)->importCsv(
                        Filament::getTenant(),
                        Account::findOrFail($data['bank_account_id']),
                        $csv,
                    );
                    Notification::make()->success()
                        ->title("Imported {$imported} transactions" . ($skipped ? ", skipped {$skipped}" : '.'))
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
