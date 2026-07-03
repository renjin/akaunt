<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(10)
                    ->disabled(fn (?Account $record) => $record?->is_system),
                TextInput::make('name')
                    ->required()
                    ->disabled(fn (?Account $record) => $record?->is_system),
                Select::make('type')
                    ->options(array_combine(Account::TYPES, array_map('ucfirst', Account::TYPES)))
                    ->required()
                    ->disabled(fn (?Account $record) => $record?->is_system),
                Select::make('subtype')
                    ->options([
                        'cash_bank' => 'Cash & Bank',
                        'accounts_receivable' => 'Accounts Receivable',
                        'inventory' => 'Inventory',
                        'current_asset' => 'Other Current Asset',
                        'fixed_asset' => 'Property, Plant & Equipment',
                        'accounts_payable' => 'Accounts Payable',
                        'sst_payable' => 'SST Payable',
                        'current_liability' => 'Other Current Liability',
                        'loan' => 'Loan',
                        'share_capital' => 'Share Capital',
                        'partner_capital' => "Partners' Capital",
                        'owner_capital' => "Owner's Capital",
                        'drawings' => 'Drawings',
                        'retained_earnings' => 'Retained Earnings',
                        'operating_revenue' => 'Operating Revenue',
                        'other_income' => 'Other Income',
                        'cogs' => 'Cost of Sales',
                        'operating_expense' => 'Operating Expense',
                    ])
                    ->required()
                    ->disabled(fn (?Account $record) => $record?->is_system),
                Select::make('parent_id')
                    ->label('Parent account')
                    ->options(fn () => Filament::getTenant()->accounts()->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->searchable(),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
