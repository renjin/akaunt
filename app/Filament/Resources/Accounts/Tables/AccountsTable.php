<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Pages\GeneralLedger;
use App\Models\Account;
use App\Models\JournalLine;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginated(false)
            // Add the most-recent journal line date as a correlated subquery so it
            // costs a single query for the whole list and stays sortable.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'last_transaction_at' => JournalLine::query()
                    ->selectRaw('MAX(journal_entries.entry_date)')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereColumn('journal_lines.account_id', 'accounts.id'),
            ]))
            // Rows are grouped into subtype sections within the active type tab.
            ->defaultGroup(
                Group::make('subtype')
                    ->getTitleFromRecordUsing(fn (Account $record): string => static::humanizeSubtype($record->subtype))
                    ->orderQueryUsing(fn (Builder $query) => $query->orderBy('subtype'))
            )
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Account $record): string => self::ledgerUrl($record)),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Account $record): string => self::ledgerUrl($record)),
                TextColumn::make('last_transaction_at')
                    ->label('Last transaction')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->url(fn (Account $record): string => self::ledgerUrl($record)),
                TextColumn::make('balance')
                    ->label('Balance')
                    ->state(fn (Account $record): string => $record->balance())
                    ->money('MYR')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('parent.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currency')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** Title-case a snake_case subtype for section headers (e.g. cash_bank -> Cash Bank). */
    public static function humanizeSubtype(?string $subtype): string
    {
        return $subtype ? ucwords(str_replace('_', ' ', $subtype)) : 'Uncategorized';
    }

    private static function ledgerUrl(Account $record): string
    {
        return GeneralLedger::getUrl([
            'account' => $record->id,
            'from' => '1970-01-01',
            'to' => today()->toDateString(),
        ]);
    }
}
