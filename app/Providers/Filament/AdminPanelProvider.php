<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->authGuard('admin')
            ->brandName('શ્રી પાતાળિયા હનુમાનજી')
            ->colors([
                'primary' => Color::Orange,
                'danger' => Color::Rose,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make('Content Management'),
                NavigationGroup::make('Booking & Donation Reports'),
                NavigationGroup::make('Store'),
                NavigationGroup::make('Financial Reports'),
                NavigationGroup::make('Portal Setup'),
                NavigationGroup::make('One-Time Setup'),
                NavigationGroup::make('Communication'),
                NavigationGroup::make('System'),
            ])
            // Lets the admin collapse the sidebar to a 64px rail on
            // desktop. State persists across reloads via Filament's
            // localStorage flag. Frees horizontal room for the
            // wider dashboard widgets + Filament tables on small
            // laptop screens.
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
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
            ])
            ->plugins([
                // Filament Shield — dynamic role + permission management UI.
                // Policies are auto-discovered from app/Policies/. The
                // `super_admin` Gate::before bypass lives in AuthServiceProvider.
                FilamentShieldPlugin::make(),
            ]);
    }
}
