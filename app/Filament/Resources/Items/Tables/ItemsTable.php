<?php

namespace App\Filament\Resources\Items\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'sales' ? 'info' : 'warning'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color('gray'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('unit_price')
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('incomeAccount.name')
                    ->label('Income account')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('expenseAccount.name')
                    ->label('Expense account')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('defaultTaxCode.name')
                    ->label('Default tax')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('unit_of_measure')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('classification_code')
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
            ->emptyStateHeading('No products or services yet')
            ->emptyStateDescription('Add the things you sell so they are one click away when you invoice.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('New product or service'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
