<?php

namespace App\Filament\Resources\Parties;

use App\Filament\Resources\Parties\Pages\CreateParty;
use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\Pages\ListParties;
use App\Filament\Resources\Parties\Schemas\PartyForm;
use App\Filament\Resources\Parties\Tables\PartiesTable;
use App\Models\Party;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PartyResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & Payments';

    protected static ?string $navigationLabel = 'Customers & Vendors';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return PartyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartiesTable::configure($table);
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
            'index' => ListParties::route('/'),
            'create' => CreateParty::route('/create'),
            'edit' => EditParty::route('/{record}/edit'),
        ];
    }
}
