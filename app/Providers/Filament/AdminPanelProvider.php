<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditClientSettings;
use App\Http\Controllers\OAuthMailboxController;
use App\Http\Controllers\PrintReportController;
use App\Http\Middleware\SetCurrentClientFromTenant;
use App\Models\Client;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * ProSend blue. Shade 600, used for buttons, has 5.8:1 contrast against white text.
     *
     * @var array<int, string>
     */
    public const BRAND_COLOR = [
        50 => 'oklch(0.97 0.016 266)',
        100 => 'oklch(0.937 0.034 266)',
        200 => 'oklch(0.882 0.066 266)',
        300 => 'oklch(0.8 0.115 266)',
        400 => 'oklch(0.69 0.175 266)',
        500 => 'oklch(0.595 0.22 266)',
        600 => 'oklch(0.525 0.235 266)',
        700 => 'oklch(0.465 0.215 266)',
        800 => 'oklch(0.4 0.178 266)',
        900 => 'oklch(0.35 0.138 266)',
        950 => 'oklch(0.255 0.09 266)',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->brandName('ProSend')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => self::BRAND_COLOR,
                'gray' => Color::Zinc,
            ])
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->unsavedChangesAlerts()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications()
            // Each client is a tenant: its data lives under /admin/{client}, and the menu switches between clients.
            ->tenant(Client::class, slugAttribute: 'slug')
            ->tenantProfile(EditClientSettings::class)
            ->searchableTenantMenu()
            ->authenticatedTenantRoutes(function (): void {
                Route::get('reports/print', PrintReportController::class)->name('reports.print');
                Route::get('mailboxes/connect/{provider}', [OAuthMailboxController::class, 'connect'])->name('mailboxes.connect');
            })
            // Fixed, client-independent address registered with Google and Microsoft.
            ->authenticatedRoutes(function (): void {
                Route::get('oauth/{provider}/callback', [OAuthMailboxController::class, 'callback'])->name('mailboxes.oauth-callback');
            })
            ->tenantMiddleware([
                SetCurrentClientFromTenant::class,
            ], isPersistent: true)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.admin.queue-stalled-banner'))
            ->navigationGroups([
                'Overview',
                'Inbox',
                'Email Operations',
                'Audiences',
                'Deliverability',
                'Infrastructure',
                'System',
                'Agency',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
