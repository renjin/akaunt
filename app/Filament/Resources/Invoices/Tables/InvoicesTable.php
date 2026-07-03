<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Invoices\InvoiceActions;
use App\Models\Invoice;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
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
                TextColumn::make('party.name')->label('Customer')->searchable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'approved' => 'info',
                        'sent' => 'warning',
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
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Invoice::STATUSES, array_map('ucfirst', Invoice::STATUSES))),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(InvoiceActions::make()),
            ]);
    }
}
