<?php

namespace App\Providers\Filament;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Books')
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantProfile(CompanySettings::class)
            ->darkMode(false, isForced: true)
            ->colors([
                'primary' => [
                    50 => '#FEF0E3',
                    100 => '#FDDBB8',
                    200 => '#FBB97C',
                    300 => '#F89640',
                    400 => '#F48120',
                    500 => '#F48120',
                    600 => '#D96910',
                    700 => '#A84F0B',
                    800 => '#7A3807',
                    900 => '#4D2204',
                    950 => '#2E1402',
                ],
                'gray' => Color::Neutral,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarWidth('16rem')
            ->navigationGroups([
                NavigationGroup::make('Sales & Payments')
                    ->collapsible(false),
                NavigationGroup::make('Purchases')
                    ->collapsible(false),
                NavigationGroup::make('Accounting')
                    ->collapsible(false),
                NavigationGroup::make('Banking')
                    ->collapsible(false),
                NavigationGroup::make('Reports')
                    ->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->navigationItems([
                NavigationItem::make('Create new')
                    ->icon('heroicon-o-plus-circle')
                    ->sort(-20)
                    ->url(fn (): string => InvoiceResource::getUrl('create')),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
