<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Services\BankTransactionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('txn_date', 'desc')
            ->columns([
                TextColumn::make('txn_date')->date()->sortable(),
                TextColumn::make('description')->searchable()->limit(50),
                TextColumn::make('account.name')
                    ->label('Bank account')
                    ->url(fn (BankTransaction $record): string => self::ledgerUrl($record, $record->account_id)),
                TextColumn::make('amount')
                    ->money('MYR')
                    ->color(fn (BankTransaction $record) => $record->direction === 'in' ? 'success' : 'danger'),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'in' ? 'Money in' : 'Money out')
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'danger'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'unmatched' => 'warning',
                        'categorized' => 'info',
                        'reconciled' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('categoryAccount.name')
                    ->label('Category')
                    ->url(fn (BankTransaction $record): ?string => $record->category_account_id
                        ? self::ledgerUrl($record, $record->category_account_id)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('party.name')
                    ->label('Customer / vendor')
                    ->url(fn (BankTransaction $record): ?string => $record->party
                        ? PartyResource::getUrl('view', ['record' => $record->party])
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('receipt_path')
                    ->label('Receipt')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->getStateUsing(fn (BankTransaction $record) => filled($record->receipt_path))
                    ->toggleable(),
                TextColumn::make('account_transactions')
                    ->label('Account transactions')
                    ->state(fn (BankTransaction $record): ?string => $record->category_account_id ? 'View' : null)
                    ->url(fn (BankTransaction $record): ?string => $record->category_account_id
                        ? self::ledgerUrl($record, $record->category_account_id)
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['unmatched' => 'Unmatched', 'categorized' => 'Categorized', 'reconciled' => 'Reconciled']),
            ])
            ->recordActions([
                Action::make('categorize')
                    ->label('Categorize')
                    ->icon('heroicon-o-tag')
                    ->color('primary')
                    ->visible(fn (BankTransaction $record) => $record->status === 'unmatched')
                    ->schema([
                        Select::make('category_account_id')
                            ->label('Category (account)')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->where('active', true)->where('subtype', '!=', 'cash_bank')->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (BankTransaction $record, array $data) {
                        try {
                            app(BankTransactionService::class)
                                ->categorize($record, Account::findOrFail($data['category_account_id']));
                            Notification::make()->success()->title('Categorized and posted.')->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('markReconciled')
                    ->label('Mark reconciled')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (BankTransaction $record) => $record->status === 'categorized')
                    ->requiresConfirmation()
                    ->action(function (BankTransaction $record) {
                        $record->forceFill(['status' => 'reconciled'])->save();
                        Notification::make()->success()->title('Reconciled.')->send();
                    }),
                EditAction::make()->visible(fn (BankTransaction $record) => $record->status === 'unmatched'),
            ]);
    }

    private static function ledgerUrl(BankTransaction $record, int $accountId): string
    {
        return GeneralLedger::getUrl([
            'account' => $accountId,
            'from' => $record->txn_date->toDateString(),
            'to' => $record->txn_date->toDateString(),
        ]);
    }
}
