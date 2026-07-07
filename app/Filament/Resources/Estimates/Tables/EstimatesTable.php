<?php

namespace App\Filament\Resources\Estimates\Tables;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\Estimates\EstimateActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Estimate;
use App\Models\JournalEntry;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EstimatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('estimate_number')->searchable()->sortable(),
                TextColumn::make('customer_ref')
                    ->label('Customer ref')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('party.name')
                    ->label('Customer')
                    ->searchable()
                    ->url(fn (Estimate $record): ?string => $record->party
                        ? PartyResource::getUrl('view', ['record' => $record->party])
                        : null),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('expiry_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray', 'sent' => 'warning', 'accepted' => 'success',
                        'expired' => 'danger', 'converted' => 'info', default => 'gray',
                    }),
                TextColumn::make('total')->money(fn (Estimate $record) => $record->currency)->sortable(),
                TextColumn::make('convertedInvoice.invoice_number')
                    ->label('Converted invoice')
                    ->url(fn (Estimate $record): ?string => $record->convertedInvoice
                        ? InvoiceResource::getUrl('view', ['record' => $record->convertedInvoice])
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('account_transactions')
                    ->label('Account transactions')
                    ->state(fn (Estimate $record): ?string => self::ledgerUrl($record) ? 'View' : null)
                    ->url(fn (Estimate $record): ?string => self::ledgerUrl($record))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->emptyStateHeading('No estimates yet')
            ->emptyStateDescription('Send a quote and convert it to an invoice once it is accepted.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('New estimate'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Estimate::STATUSES, array_map('ucfirst', Estimate::STATUSES))),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(EstimateActions::make()),
            ]);
    }

    private static function ledgerUrl(Estimate $record): ?string
    {
        $invoice = $record->convertedInvoice;
        if (! $invoice || ! $invoice->isPosted()) {
            return null;
        }

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
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
            'from' => $invoice->issue_date->toDateString(),
            'to' => $invoice->issue_date->toDateString(),
        ]);
    }
}
