<?php

namespace App\Providers\Filament;

use App\Modules\Analytics\Filament\Widgets\EnrollmentStatsWidget;
use App\Modules\Analytics\Filament\Widgets\ExamStatsWidget;
use App\Shared\Middleware\EnsureCurrentWorkspace;
use App\Shared\Middleware\EnsureFilamentAccess;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
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
            // One enrolment, both surfaces: this reads the same
            // user_security_settings row the API writes, so a teacher who set up
            // their authenticator app in the product signs in with it here too.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            /*
            | The product is «المتميز», and the panel said «EduPlatform» — the
            | Laravel skeleton's `APP_NAME`, which nobody had ever set. The
            | frontend has carried the real name since 002 through
            | NEXT_PUBLIC_PLATFORM_NAME; the backend was simply never told, so
            | the login page, every notification mail and the browser tab all
            | quoted a placeholder. `.env` now carries it, and this makes the
            | panel independent of whether a deployment remembers to.
            */
            ->brandName(config('app.name') === 'Laravel' ? 'المتميز' : (string) config('app.name'))
            /*
            | ⚠️ THE SAME ONE-ASSET TECHNIQUE THE PUBLIC SITE USES, not a second
            | export. `wordmark-mask.png` is a silhouette: the MASK carries the
            | shape and `background-color` carries the brand, so one file serves
            | light and dark. Shipping a coloured PNG here instead would be a
            | second file to re-export the day the maroon changes, and the two
            | would disagree for exactly as long as nobody noticed.
            |
            | Inline rather than a compiled theme, because a Filament theme means
            | a Vite build step in the backend for eleven lines of CSS.
            */
            ->brandLogo(new HtmlString('<span class="mt-wordmark" role="img" aria-label="'.e((string) config('app.name')).'"></span>'))
            ->brandLogoHeight('2.75rem')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<style>'
                    .'.mt-wordmark{display:block;height:2.75rem;aspect-ratio:941/789;'
                    // ⚠️ A LITERAL, NOT `var(--primary-600)`. Filament defines its
                    // palette in `oklch()`, so the `rgb(var(--…))` form every
                    // Tailwind habit reaches for produces an INVALID colour and
                    // paints the mark transparent — a logo that is present, sized
                    // and invisible, which is the hardest kind to notice. And the
                    // maroon is the product's, not the panel's: the wordmark is
                    // the same mark the public site paints.
                    .'background-color:#8a1538;'
                    ."mask-image:url('".asset('brand/wordmark-mask.png')."');"
                    .'mask-size:contain;mask-repeat:no-repeat;mask-position:center;}'
                    // Warm white in the dark, the same swap globals.css makes.
                    .'.dark .mt-wordmark{background-color:#f5efe9;}'
                    .'</style>'
                ),
            )
            /*
            | ⚠️ THE BRAND'S MAROON, AND IT USED TO BE `Color::Indigo` — the
            | Laravel skeleton's default, beside a maroon wordmark. Two palettes
            | in one frame is the tell of a screen nobody looked at: the login
            | page would have shown the product's logo above a button from a
            | different product. `#8a1538` is `--color-primary` in the frontend's
            | `@theme`, which is where the decision lives.
            */
            ->colors([
                /*
                | ⚠️ ELEVEN SHADES, NOT `Color::hex('#8a1538')` — AND THE
                | DIFFERENCE IS A PINK BUTTON. Filament anchors a generated ramp
                | at shade 500 and builds lightness around it, so handing it a
                | DARK maroon (L≈0.43 in oklch) makes 500 the mid-tone and 600 —
                | the shade every primary button uses — comes out at L≈0.60: a
                | salmon pink under a maroon logo, on the login page.
                |
                | The ramp below is the brand mixed toward white above 600 and
                | toward black below it, so `600` IS `#8a1538` and the button is
                | the colour the product actually uses. `50` lands within a shade
                | of `--color-primary-soft` in the frontend's `@theme`, which is
                | where these decisions live.
                */
                'primary' => [
                    50 => '#f8f1f3',
                    100 => '#f1e3e7',
                    200 => '#e2c5cd',
                    300 => '#d0a1af',
                    400 => '#b3677e',
                    500 => '#9c3856',
                    600 => '#8a1538',
                    700 => '#751230',
                    800 => '#610f27',
                    900 => '#4c0c1f',
                    950 => '#300714',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // Modules keep their own admin screens next to the code they administer.
            // Add a line per module; generalise to a scan when there are enough of
            // them to make the list a chore.
            ->discoverResources(
                in: app_path('Modules/Marketplace/Filament/Resources'),
                for: 'App\Modules\Marketplace\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Payments/Filament/Resources'),
                for: 'App\Modules\Payments\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Tenancy/Filament/Resources'),
                for: 'App\Modules\Tenancy\Filament\Resources',
            )
            /*
            | The role screen (Shield), configured in `config/filament-shield.php`
            | to GENERATE NOTHING: the permission names are this product's own
            | constants and the policies are hand-written. What the plugin
            | contributes is the part that was missing — a place to tick a
            | permission onto a role without a migration.
            |
            | ⚠️ Its resource is scoped to the current workspace by
            | `Tenancy\Filament\ScopeRolesToWorkspace`, registered below. Roles
            | are rows with a `team_id` and no global scope of their own, so an
            | unscoped list hands one teacher every other teacher's roles.
            */
            ->plugin(FilamentShieldPlugin::make())
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(
                in: app_path('Modules/Tenancy/Filament/Pages'),
                for: 'App\Modules\Tenancy\Filament\Pages',
            )
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
