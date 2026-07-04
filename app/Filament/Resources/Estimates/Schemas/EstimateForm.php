<?php

namespace App\Filament\Resources\Estimates\Schemas;

use App\Models\Account;
use App\Models\Estimate;
use App\Models\Item;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EstimateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (?Estimate $record) => $record !== null && $record->status !== 'draft')
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        Select::make('party_id')
                            ->label('Customer')
                            ->options(fn () => Filament::getTenant()->parties()
                                ->whereIn('role', ['customer', 'both'])->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        DatePicker::make('issue_date')->required()->default(today()),
                        DatePicker::make('expiry_date')->default(today()->addDays(30)),
                    ]),
                Repeater::make('lines')
                    ->relationship('lines')
                    ->schema([
                        Select::make('item_id')
                            ->label('Item')
                            ->options(fn () => Filament::getTenant()->items()
                                ->where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (! $state) {
                                    return;
                                }
                                $item = Item::find($state);
                                $set('description', $item->name);
                                $set('unit_price', $item->unit_price);
                                $set('tax_code_id', $item->default_tax_code_id);
                                $set('income_account_id', $item->income_account_id);
                                $set('classification_code', $item->classification_code);
                            }),
                        TextInput::make('description')->required()->columnSpan(2),
                        TextInput::make('quantity')->numeric()->default(1),
                        TextInput::make('unit_price')->numeric()->default(0),
                        Select::make('tax_code_id')
                            ->label('Tax')
                            ->options(fn () => Filament::getTenant()->taxCodes()
                                ->where('active', true)->pluck('name', 'id')),
                        Select::make('income_account_id')
                            ->label('Income account')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->where('type', 'income')->where('active', true)->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"])),
                    ])
                    ->columns(7)
                    ->defaultItems(1)
                    ->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }
}
