<?php

namespace App\Filament\Resources\Bills\Tables;

use App\Filament\Resources\Bills\BillActions;
use App\Models\Bill;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('bill_date', 'desc')
            ->columns([
                TextColumn::make('bill_number')->searchable(),
                TextColumn::make('party.name')->label('Vendor')->searchable(),
                TextColumn::make('bill_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'approved' => 'info',
                        'partial' => 'warning',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total')->money(fn (Bill $record) => $record->currency)->sortable(),
                TextColumn::make('balance_due')
                    ->state(fn (Bill $record) => $record->balance_due)
                    ->money(fn (Bill $record) => $record->currency),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Bill::STATUSES, array_map('ucfirst', Bill::STATUSES))),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(BillActions::make()),
            ]);
    }
}
