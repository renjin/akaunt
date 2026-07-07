<?php

namespace App\Filament\Resources\Parties;

use App\Filament\Resources\Parties\Pages\CreateParty;
use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\Pages\ListCustomers;
use App\Filament\Resources\Parties\Pages\ListParties;
use App\Filament\Resources\Parties\Pages\ListVendors;
use App\Filament\Resources\Parties\Pages\ViewParty;
use App\Filament\Resources\Parties\Schemas\PartyForm;
use App\Filament\Resources\Parties\Schemas\PartyInfolist;
use App\Filament\Resources\Parties\Tables\PartiesTable;
use App\Models\Party;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PartyResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & Payments';

    protected static ?string $navigationLabel = 'Customers & Vendors';

    protected static ?string $modelLabel = 'customer or vendor';

    protected static ?string $pluralModelLabel = 'Customers & Vendors';

    protected static ?int $navigationSort = 20;

    /**
     * Wave-style split navigation: the single Party resource surfaces as two
     * sidebar entries — "Customers" under Sales & Payments and "Vendors" under
     * Purchases — each pointing at a role-scoped list page.
     */
    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make('Customers')
                ->icon('heroicon-o-user-group')
                ->group('Sales & Payments')
                ->sort(20)
                ->url(fn (): string => static::getUrl('customers'))
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName().'.customers')),
            NavigationItem::make('Vendors')
                ->icon('heroicon-o-building-storefront')
                ->group('Purchases')
                ->sort(20)
                ->url(fn (): string => static::getUrl('vendors'))
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName().'.vendors')),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return PartyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartiesTable::configure($table);
    }

    /** Delete action that refuses to remove a contact with invoices or bills. */
    public static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, Party $record) {
                if ($record->invoices()->exists() || $record->bills()->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('This contact has invoices or bills and cannot be deleted.')
                        ->send();

                    $action->cancel();
                }
            });
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
            // Role-scoped lists must register before the '/{record}' view route.
            'customers' => ListCustomers::route('/customers'),
            'vendors' => ListVendors::route('/vendors'),
            'create' => CreateParty::route('/create'),
            'view' => ViewParty::route('/{record}'),
            'edit' => EditParty::route('/{record}/edit'),
        ];
    }
}
