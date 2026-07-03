<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('type')
                    ->options(['service' => 'Service', 'goods' => 'Goods'])
                    ->default('service')
                    ->required(),
                TextInput::make('sku')->label('SKU'),
                Textarea::make('description'),
                TextInput::make('unit_price')->numeric()->default(0)->prefix('RM'),
                TextInput::make('unit_of_measure')->placeholder('e.g. hour, unit, kg'),
                Select::make('income_account_id')
                    ->label('Income account')
                    ->options(fn () => self::accountOptions('income')),
                Select::make('expense_account_id')
                    ->label('Expense account')
                    ->options(fn () => self::accountOptions('expense')),
                Select::make('default_tax_code_id')
                    ->label('Default tax')
                    ->options(fn () => Filament::getTenant()->taxCodes()->where('active', true)->pluck('name', 'id')),
                TextInput::make('classification_code')
                    ->label('LHDN classification code')
                    ->maxLength(3)
                    ->placeholder('e.g. 008'),
                Toggle::make('active')->default(true),
            ]);
    }

    private static function accountOptions(string $type): array
    {
        return Filament::getTenant()->accounts()
            ->where('type', $type)->where('active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"])
            ->all();
    }
}
