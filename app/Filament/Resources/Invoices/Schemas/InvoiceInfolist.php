<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = fn (Invoice $record) => $record->currency;

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('invoice_number')->label('Invoice number'),
                    TextEntry::make('party.name')->label('Customer'),
                    TextEntry::make('issue_date')->date(),
                    TextEntry::make('due_date')->date()->placeholder('—'),
                    TextEntry::make('status')
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
                    TextEntry::make('einvoice_status')
                        ->label('e-Invoice')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === 'not_applicable'
                            ? 'N/A'
                            : ucfirst(str_replace('_', ' ', $state)))
                        ->color(fn (string $state) => match ($state) {
                            'pending_review' => 'warning',
                            'submitted' => 'info',
                            'validated' => 'success',
                            'rejected', 'cancelled' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('currency'),
                    TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Lines')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('description')->columnSpan(2),
                            TextEntry::make('quantity')->label('Qty'),
                            TextEntry::make('unit_price')
                                ->money(fn (InvoiceLine $record) => $record->invoice->currency),
                            TextEntry::make('line_total')
                                ->label('Amount')
                                ->money(fn (InvoiceLine $record) => $record->invoice->currency),
                        ]),
                ]),
            Section::make('Totals')
                ->columns(5)
                ->schema([
                    TextEntry::make('subtotal')->money($currency),
                    TextEntry::make('tax_total')->label('Tax')->money($currency),
                    TextEntry::make('total')->money($currency)->weight('bold'),
                    TextEntry::make('amount_paid')->money($currency),
                    TextEntry::make('balance_due')
                        ->state(fn (Invoice $record) => $record->balance_due)
                        ->money($currency)
                        ->weight('bold'),
                ]),
            Section::make('Payments')
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->hiddenLabel()
                        ->columns(4)
                        ->placeholder('No payments recorded yet.')
                        ->schema([
                            TextEntry::make('payment.payment_date')->label('Date')->date(),
                            TextEntry::make('amount')
                                ->money(fn (PaymentAllocation $record) => $record->payment?->currency ?? 'MYR'),
                            TextEntry::make('payment.method')
                                ->label('Method')
                                ->formatStateUsing(fn (?string $state) => in_array($state, ['fpx', 'duitnow'])
                                    ? strtoupper((string) $state)
                                    : ucfirst(str_replace('_', ' ', (string) $state))),
                            TextEntry::make('payment.reference')->label('Reference')->placeholder('—'),
                        ]),
                ]),
        ]);
    }
}
