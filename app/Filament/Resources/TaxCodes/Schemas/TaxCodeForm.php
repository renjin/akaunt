<?php

namespace App\Filament\Resources\TaxCodes\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TaxCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('tax_type')
                    ->options([
                        'sales' => 'Sales Tax',
                        'service' => 'Service Tax',
                        'exempt' => 'Exempt',
                        'zero' => 'Zero-rated',
                        'not_applicable' => 'Not applicable (unregistered)',
                    ])
                    ->required(),
                TextInput::make('rate')->numeric()->suffix('%')->default(0)->required(),
                Select::make('sst_payable_account_id')
                    ->label('SST payable account')
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('type', 'liability')->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"])),
                TextInput::make('myinvois_tax_type_code')
                    ->label('MyInvois tax type code')
                    ->maxLength(3),
                Toggle::make('active')->default(true),
            ]);
    }
}
