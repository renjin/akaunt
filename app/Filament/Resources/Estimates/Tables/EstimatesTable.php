<?php

namespace App\Filament\Resources\Estimates\Tables;

use App\Filament\Resources\Estimates\EstimateActions;
use App\Models\Estimate;
use Filament\Actions\ActionGroup;
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
                TextColumn::make('party.name')->label('Customer')->searchable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('expiry_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray', 'sent' => 'warning', 'accepted' => 'success',
                        'expired' => 'danger', 'converted' => 'info', default => 'gray',
                    }),
                TextColumn::make('total')->money(fn (Estimate $record) => $record->currency)->sortable(),
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
}
