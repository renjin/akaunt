<?php

namespace App\Filament\Resources\Parties\Tables;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Party;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'customer' => 'info',
                        'vendor' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('tin')
                    ->label('TIN')
                    ->searchable(),
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(function (Party $record): string {
                        $sum = fn ($relation) => (string) $relation
                            ->whereNotIn('status', ['draft', 'void'])
                            ->sum(DB::raw('total - amount_paid'));

                        $total = '0';
                        if ($record->isCustomer()) {
                            $total = bcadd($total, $sum($record->invoices()), 2);
                        }
                        if ($record->isVendor()) {
                            $total = bcadd($total, $sum($record->bills()), 2);
                        }

                        return $total;
                    })
                    ->money('MYR'),
                TextColumn::make('registration_scheme')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registration_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sst_registration_no')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address_line1')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address_line2')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('postcode')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country_code')
                    ->searchable()
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
            ->recordUrl(fn (Party $record) => PartyResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('createInvoice')
                    ->label('Create invoice')
                    ->icon('heroicon-o-document-plus')
                    ->url(fn (): string => InvoiceResource::getUrl('create'))
                    ->visible(fn (Party $record): bool => $record->isCustomer()),
                Action::make('createBill')
                    ->label('Create bill')
                    ->icon('heroicon-o-document-plus')
                    ->url(fn (): string => BillResource::getUrl('create'))
                    ->visible(fn (Party $record): bool => $record->isVendor()),
                EditAction::make(),
                PartyResource::guardedDeleteAction(),
            ]);
    }
}
