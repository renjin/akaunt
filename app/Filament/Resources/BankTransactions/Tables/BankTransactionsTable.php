<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Services\BankTransactionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
                TextColumn::make('account.name')->label('Bank account'),
                TextColumn::make('amount')
                    ->money('MYR')
                    ->color(fn (BankTransaction $record) => $record->direction === 'in' ? 'success' : 'danger'),
                TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'danger'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'unmatched' => 'warning',
                        'categorized' => 'info',
                        'reconciled' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('categoryAccount.name')->label('Category')->placeholder('—'),
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
}
