<?php

namespace App\Filament\Resources\Bills\Schemas;

use App\Models\Account;
use App\Models\Item;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (?\App\Models\Bill $record) => $record !== null && $record->status !== 'draft')
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        Select::make('party_id')
                            ->label('Vendor')
                            ->options(fn () => Filament::getTenant()->parties()
                                ->whereIn('role', ['vendor', 'both'])->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('bill_number')->label("Vendor's invoice no."),
                        DatePicker::make('bill_date')->required()->default(today()),
                        DatePicker::make('due_date'),
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
                                $set('expense_account_id', $item->expense_account_id);
                            }),
                        TextInput::make('description')->required()->columnSpan(2),
                        TextInput::make('quantity')->numeric()->default(1),
                        TextInput::make('unit_price')->numeric()->default(0),
                        Select::make('tax_code_id')
                            ->label('SST paid')
                            ->helperText('Folded into the expense — SST is not recoverable')
                            ->options(fn () => Filament::getTenant()->taxCodes()
                                ->where('active', true)->pluck('name', 'id')),
                        Select::make('expense_account_id')
                            ->label('Expense account')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->whereIn('type', ['expense', 'asset'])->where('active', true)->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"])),
                    ])
                    ->columns(7)
                    ->defaultItems(1)
                    ->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }
}
