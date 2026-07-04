<?php

namespace App\Filament\Resources\RecurringInvoices\Tables;

use App\Models\RecurringInvoice;
use App\Services\RecurringInvoiceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecurringInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('party.name')->label('Customer')->searchable(),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('next_run_date')->label('Next invoice')->date()->sortable(),
                TextColumn::make('last_run_date')->label('Last generated')->date()->placeholder('—'),
                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('generateNow')
                    ->label('Generate now')
                    ->icon('heroicon-o-bolt')
                    ->visible(fn (RecurringInvoice $record) => $record->active && $record->lines()->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Creates and approves the next invoice immediately, ahead of schedule.')
                    ->action(function (RecurringInvoice $record) {
                        $invoice = app(RecurringInvoiceService::class)->generate($record);
                        Notification::make()->success()->title("Generated invoice {$invoice->invoice_number}.")->send();
                    }),
            ]);
    }
}
