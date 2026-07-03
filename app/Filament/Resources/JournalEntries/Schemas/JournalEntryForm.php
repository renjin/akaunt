<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('entry_date')
                    ->required()
                    ->default(today()),
                TextInput::make('reference'),
                TextInput::make('description'),
                Repeater::make('lines')
                    ->schema([
                        Select::make('account_id')
                            ->label('Account')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->where('active', true)->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                            ->searchable()
                            ->required(),
                        TextInput::make('debit')->numeric()->default(0)->minValue(0),
                        TextInput::make('credit')->numeric()->default(0)->minValue(0),
                        TextInput::make('memo'),
                    ])
                    ->columns(4)
                    ->minItems(2)
                    ->defaultItems(2)
                    ->columnSpanFull(),
            ]);
    }
}
