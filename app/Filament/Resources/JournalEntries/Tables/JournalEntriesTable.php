<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['source', 'lines.account'])
                ->withSum('lines as amount', 'debit_base'))
            ->defaultSort('entry_date', 'desc')
            ->columns([
                TextColumn::make('entry_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('reference')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->state(fn ($record): string => $record->source_type ? class_basename($record->source_type) : 'Manual')
                    ->url(fn (JournalEntry $record): ?string => self::sourceUrl($record->source))
                    ->color(fn (string $state): string => $state === 'Manual' ? 'gray' : 'info'),
                TextColumn::make('account_transactions')
                    ->label('Account transactions')
                    ->state(fn (JournalEntry $record): ?string => self::ledgerUrl($record) ? 'View' : null)
                    ->url(fn (JournalEntry $record): ?string => self::ledgerUrl($record))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->money('MYR')
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ]);
        // ponytail: no row edit/delete — posted ledger entries are immutable; corrections are new entries
    }

    private static function ledgerUrl(JournalEntry $record): ?string
    {
        $line = $record->lines->first(fn ($line) => $line->account !== null);
        if (! $line) {
            return null;
        }

        return GeneralLedger::getUrl([
            'account' => $line->account_id,
            'from' => $record->entry_date->toDateString(),
            'to' => $record->entry_date->toDateString(),
        ]);
    }

    private static function sourceUrl(?Model $source): ?string
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

            return self::sourceUrl($allocation?->allocatable);
        }

        return null;
    }
}
