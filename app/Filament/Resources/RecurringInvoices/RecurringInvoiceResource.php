<?php

namespace App\Filament\Resources\RecurringInvoices;

use App\Filament\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Filament\Resources\RecurringInvoices\Schemas\RecurringInvoiceForm;
use App\Filament\Resources\RecurringInvoices\Tables\RecurringInvoicesTable;
use App\Models\RecurringInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RecurringInvoiceResource extends Resource
{
    protected static ?string $model = RecurringInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & Payments';

    public static function form(Schema $schema): Schema
    {
        return RecurringInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringInvoicesTable::configure($table);
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
            'index' => ListRecurringInvoices::route('/'),
            'create' => CreateRecurringInvoice::route('/create'),
            'edit' => EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
