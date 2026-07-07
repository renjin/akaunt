<?php

namespace App\Filament\Resources\BankTransactions\Schemas;

use App\Models\Account;
use App\Models\BankTransaction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BankTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (?BankTransaction $record) => $record !== null && $record->status !== 'unmatched')
            ->columns(2)
            ->components([
                Select::make('account_id')
                    ->label('Bank / cash account')
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->required(),
                DatePicker::make('txn_date')->required()->default(today()),
                TextInput::make('description')->required()->columnSpanFull(),
                TextInput::make('amount')->numeric()->required()->minValue(0.01),
                Select::make('direction')
                    ->options(['in' => 'Money in', 'out' => 'Money out'])
                    ->default('out')
                    ->required()
                    ->live(),
                Select::make('category_account_id')
                    ->label('Category')
                    ->helperText('The income or expense account this transaction belongs to.')
                    ->options(fn (Get $get) => Filament::getTenant()->accounts()
                        ->where('active', true)
                        ->where('type', $get('direction') === 'in' ? 'income' : 'expense')
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->searchable(),
                Select::make('party_id')
                    ->label(fn (Get $get) => $get('direction') === 'in' ? 'Customer' : 'Vendor')
                    ->options(fn (Get $get) => Filament::getTenant()->parties()
                        ->whereIn('role', $get('direction') === 'in' ? ['customer', 'both'] : ['vendor', 'both'])
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                FileUpload::make('receipt_path')
                    ->label('Receipt')
                    ->disk('public')
                    ->directory('receipts')
                    ->downloadable()
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->columnSpanFull(),
            ]);
    }
}
