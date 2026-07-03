<?php

namespace App\Filament\Resources\BankTransactions\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (?\App\Models\BankTransaction $record) => $record !== null && $record->status !== 'unmatched')
            ->components([
                Select::make('account_id')
                    ->label('Bank / cash account')
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->required(),
                DatePicker::make('txn_date')->required()->default(today()),
                TextInput::make('description')->required(),
                TextInput::make('amount')->numeric()->required()->minValue(0.01),
                Select::make('direction')
                    ->options(['in' => 'Money in', 'out' => 'Money out'])
                    ->default('out')
                    ->required(),
            ]);
    }
}
