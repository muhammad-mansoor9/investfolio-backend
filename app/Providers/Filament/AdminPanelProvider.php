<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            ->authGuard('admin')
            ->brandName('Investfolio Admin')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('admin-ui/favicon.png'))
            ->font('Inter')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Zinc,
                'info' => Color::Sky,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->spa()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseTransactions()
            ->navigationGroups([
                NavigationGroup::make('Data quality'),
                NavigationGroup::make('Market data'),
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Billing'),
                NavigationGroup::make('System')->collapsed(),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Open the web app')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn () => config('app.frontend_url'), shouldOpenInNewTab: true),
                MenuItem::make()
                    ->label('Server logs')
                    ->icon('heroicon-o-document-text')
                    ->url(fn () => url('/log-viewer'), shouldOpenInNewTab: true),
            ])
            // Plain CSS instead of a Filament theme so no frontend build step is needed.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => '<link rel="stylesheet" href="'.asset('admin-ui/admin.css').'?v=1">')
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => app()->isProduction()
                ? ''
                : Blade::render('<x-filament::badge color="warning" icon="heroicon-o-wrench-screwdriver">{{ $env }}</x-filament::badge>', ['env' => ucfirst(app()->environment())]))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
