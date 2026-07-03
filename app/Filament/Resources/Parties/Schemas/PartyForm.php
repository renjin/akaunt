<?php

namespace App\Filament\Resources\Parties\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->columnSpanFull(),
                        Select::make('role')
                            ->options(['customer' => 'Customer', 'vendor' => 'Vendor', 'both' => 'Both'])
                            ->default('customer')
                            ->required(),
                        TextInput::make('email')->email(),
                        TextInput::make('phone'),
                    ]),
                Section::make('Malaysian identity (e-Invoice ready)')
                    ->columns(2)
                    ->schema([
                        Select::make('registration_scheme')
                            ->label('ID type')
                            ->options([
                                'BRN' => 'BRN — Business registration (SSM)',
                                'NRIC' => 'NRIC — MyKad',
                                'PASSPORT' => 'Passport',
                                'ARMY' => 'Army ID',
                            ]),
                        TextInput::make('registration_number')->label('ID number'),
                        TextInput::make('tin')->label('TIN (LHDN)'),
                        TextInput::make('sst_registration_no')->label('SST registration no.'),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address_line1')->columnSpanFull(),
                        TextInput::make('address_line2')->columnSpanFull(),
                        TextInput::make('city'),
                        TextInput::make('state'),
                        TextInput::make('postcode'),
                        TextInput::make('country_code')->default('MY')->maxLength(2),
                    ]),
            ]);
    }
}
