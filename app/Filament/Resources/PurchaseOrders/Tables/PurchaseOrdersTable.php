<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderActions;
use App\Models\PurchaseOrder;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label('Purchase order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('party.name')
                    ->label('Vendor')
                    ->searchable()
                    ->url(fn (PurchaseOrder $record): ?string => $record->party
                        ? PartyResource::getUrl('view', ['record' => $record->party])
                        : null),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('expected_date')->date()->placeholder('—')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'sent' => 'warning',
                        'approved' => 'success',
                        'converted' => 'info',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total')->money(fn (PurchaseOrder $record) => $record->currency)->sortable(),
                TextColumn::make('convertedBill.bill_number')
                    ->label('Converted bill')
                    ->state(fn (PurchaseOrder $record): ?string => $record->convertedBill
                        ? ($record->convertedBill->bill_number ?: 'Bill #'.$record->convertedBill->id)
                        : null)
                    ->url(fn (PurchaseOrder $record): ?string => $record->convertedBill
                        ? BillResource::getUrl('view', ['record' => $record->convertedBill])
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(PurchaseOrder::STATUSES, array_map('ucfirst', PurchaseOrder::STATUSES))),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(PurchaseOrderActions::make()),
            ]);
    }
}
