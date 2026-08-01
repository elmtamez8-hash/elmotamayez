<?php

namespace App\Providers\Filament;

use App\Modules\Analytics\Filament\Widgets\EnrollmentStatsWidget;
use App\Modules\Analytics\Filament\Widgets\ExamStatsWidget;
use App\Shared\Middleware\EnsureCurrentWorkspace;
use App\Shared\Middleware\EnsureFilamentAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
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
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // Modules keep their own admin screens next to the code they administer.
            // Add a line per module; generalise to a scan when there are enough of
            // them to make the list a chore.
            ->discoverResources(
                in: app_path('Modules/Marketplace/Filament/Resources'),
                for: 'App\Modules\Marketplace\Filament\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                EnrollmentStatsWidget::class,
                ExamStatsWidget::class,
            ])
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
                // Must run after Authenticate (needs the user) and before any
                // role check: spatie is in team mode, so permission lookups are
                // meaningless until the team id matches the current workspace.
                EnsureCurrentWorkspace::class,
                EnsureFilamentAccess::class,
            ]);
    }
}
