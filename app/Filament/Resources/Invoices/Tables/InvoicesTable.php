<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable(),
                TextColumn::make('party.name')
                    ->label('Customer')
                    ->searchable()
                    ->url(fn (Invoice $record): ?string => $record->party
                        ? PartyResource::getUrl('view', ['record' => $record->party])
                        : null),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable()
                    ->description(fn (Invoice $record) => $record->due_date && $record->balance_due > 0 && $record->status !== 'draft'
                        ? $record->due_date->diffForHumans()
                        : null)
                    ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'approved', 'sent' => 'info',
                        'partial' => 'warning',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total')->money(fn (Invoice $record) => $record->currency)->sortable(),
                TextColumn::make('amount_paid')->money(fn (Invoice $record) => $record->currency)->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance_due')
                    ->state(fn (Invoice $record) => $record->balance_due)
                    ->money(fn (Invoice $record) => $record->currency),
                TextColumn::make('unpaid_by_customer')
                    ->label('Unpaid by customer')
                    ->state(fn (Invoice $record) => self::unpaidPosition($record))
                    ->toggleable(),
                TextColumn::make('account_transactions')
                    ->label('Account transactions')
                    ->state(fn (Invoice $record): ?string => self::ledgerUrl($record) ? 'View' : null)
                    ->url(fn (Invoice $record): ?string => self::ledgerUrl($record))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('einvoice_status')
                    ->label('e-Invoice')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'not_applicable' => 'gray',
                        'pending_review' => 'warning',
                        'submitted' => 'info',
                        'validated' => 'success',
                        'rejected', 'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => $state === 'not_applicable'
                        ? 'N/A'
                        : ucfirst(str_replace('_', ' ', $state)))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Invoice::STATUSES, array_map('ucfirst', Invoice::STATUSES))),
            ])
            ->recordUrl(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                InvoiceActions::sendReminder()->button()->label('Send reminder'),
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make(InvoiceActions::make(except: ['sendReminder'])),
            ]);
    }

    /**
     * "1 of 3" — this invoice's position (by due date) among the customer's
     * unpaid invoices, cached per request to avoid one query per row.
     * ponytail: per-customer query memoised in a static; fine at 10 rows/page.
     */
    private static function unpaidPosition(Invoice $record): ?string
    {
        if ($record->balance_due <= 0 || in_array($record->status, ['draft', 'void', 'paid'], true)) {
            return null;
        }

        static $cache = [];

        $ids = $cache[$record->party_id] ??= Invoice::query()
            ->where('company_id', $record->company_id)
            ->where('party_id', $record->party_id)
            ->whereNotIn('status', ['draft', 'void', 'paid'])
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('due_date')
            ->pluck('id')
            ->all();

        $position = array_search($record->id, $ids, true);

        return $position === false ? null : ($position + 1).' of '.count($ids);
    }

    private static function ledgerUrl(Invoice $record): ?string
    {
        if (! $record->isPosted()) {
            return null;
        }

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', $record->getMorphClass())
            ->where('source_id', $record->id)
            ->first();

        $account = $entry?->lines
            ->first(fn ($line) => $line->account && $line->account->type === 'income')
            ?->account
            ?? $entry?->lines->first()?->account;

        if (! $account) {
            return null;
        }

        return GeneralLedger::getUrl([
            'account' => $account->id,
            'from' => $record->issue_date->toDateString(),
            'to' => $record->issue_date->toDateString(),
        ]);
    }
}
