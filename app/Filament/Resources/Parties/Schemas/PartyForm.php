<?php

namespace App\Filament\Resources\Parties\Schemas;

use Filament\Forms\Components\Repeater;
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
                Section::make('Basic information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Customer / vendor name')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('role')
                            ->options(['customer' => 'Customer', 'vendor' => 'Vendor', 'both' => 'Both'])
                            // Entry point decides the default: "Add a customer" / "Add a vendor" pass ?role=.
                            ->default(fn (): string => in_array(request()->query('role'), ['customer', 'vendor', 'both'], true)
                                ? request()->query('role')
                                : 'customer')
                            ->required(),
                    ]),
                Section::make('Primary contact')
                    ->description('The main person or address to reach this customer or vendor.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')->email(),
                        TextInput::make('phone'),
                    ]),
                Section::make('Additional contacts')
                    ->description('Other people at this business you may correspond with.')
                    ->schema([
                        Repeater::make('contacts')
                            ->relationship('contacts')
                            ->hiddenLabel()
                            ->columns(3)
                            ->addActionLabel('Add contact')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->schema([
                                TextInput::make('name')->required(),
                                TextInput::make('email')->email(),
                                TextInput::make('phone'),
                            ]),
                    ]),
                Section::make('Registration & tax')
                    ->description('Malaysian identity (e-Invoice ready).')
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
