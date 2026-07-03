<?php

namespace App\Filament\Resources\TaxCodes;

use App\Filament\Resources\TaxCodes\Pages\CreateTaxCode;
use App\Filament\Resources\TaxCodes\Pages\EditTaxCode;
use App\Filament\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Filament\Resources\TaxCodes\Schemas\TaxCodeForm;
use App\Filament\Resources\TaxCodes\Tables\TaxCodesTable;
use App\Models\TaxCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TaxCodeResource extends Resource
{
    protected static ?string $model = TaxCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return TaxCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
            'create' => CreateTaxCode::route('/create'),
            'edit' => EditTaxCode::route('/{record}/edit'),
        ];
    }
}
