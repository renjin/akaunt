<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\Account;
use App\Services\PayrollJournalService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use InvalidArgumentException;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayroll')
                ->label('Record payroll')
                ->icon('heroicon-o-users')
                ->modalDescription('Enter the monthly totals from your payroll provider (e.g. hr.my payroll export). Posts one balanced entry: salary + employer contributions to expenses, statutory amounts to their payables, net pay from the bank.')
                ->schema([
                    DatePicker::make('payment_date')->required()->default(today()),
                    Select::make('bank_account_id')
                        ->label('Net pay paid from')
                        ->options(fn () => Filament::getTenant()->accounts()
                            ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                        ->required(),
                    Section::make('Monthly totals (RM)')
                        ->columns(2)
                        ->schema([
                            TextInput::make('gross')->label('Gross salaries')->numeric()->required(),
                            TextInput::make('pcb')->label('PCB / MTD withheld')->numeric()->default(0),
                            TextInput::make('employer_epf')->label('EPF — employer')->numeric()->default(0),
                            TextInput::make('employee_epf')->label('EPF — employee')->numeric()->default(0),
                            TextInput::make('employer_socso_eis')->label('SOCSO + EIS — employer')->numeric()->default(0),
                            TextInput::make('employee_socso_eis')->label('SOCSO + EIS — employee')->numeric()->default(0),
                            TextInput::make('hrdf')->label('HRD Corp levy')->numeric()->default(0),
                            TextInput::make('reference')->label('Reference')->placeholder('e.g. PAYROLL-2026-07'),
                        ]),
                ])
                ->action(function (array $data) {
                    try {
                        app(PayrollJournalService::class)->post(
                            Filament::getTenant(),
                            $data['payment_date'],
                            $data,
                            Account::findOrFail($data['bank_account_id']),
                            $data['reference'] ?? null,
                        );
                        Notification::make()->success()->title('Payroll posted to the ledger.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('remitStatutory')
                ->label('Remit statutory')
                ->icon('heroicon-o-building-library')
                ->modalDescription('Record paying a statutory body (KWSP / PERKESO / LHDN / HRD Corp) from the bank, clearing the payable.')
                ->schema([
                    Select::make('account_code')
                        ->label('Statutory payable')
                        ->options([
                            '2210' => 'EPF Payable (KWSP)',
                            '2220' => 'SOCSO & EIS Payable (PERKESO)',
                            '2230' => 'PCB Payable (LHDN)',
                            '2240' => 'HRD Corp Levy Payable',
                        ])
                        ->required(),
                    TextInput::make('amount')->numeric()->required(),
                    DatePicker::make('payment_date')->required()->default(today()),
                    Select::make('bank_account_id')
                        ->label('Paid from')
                        ->options(fn () => Filament::getTenant()->accounts()
                            ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(PayrollJournalService::class)->remitStatutory(
                            Filament::getTenant(),
                            $data['account_code'],
                            $data['amount'],
                            $data['payment_date'],
                            Account::findOrFail($data['bank_account_id']),
                        );
                        Notification::make()->success()->title('Statutory remittance posted.')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
