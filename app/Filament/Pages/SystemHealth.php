<?php

namespace App\Filament\Pages;

use App\Services\SystemHealth as SystemHealthChecks;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Whether the installation is working: scheduler, queue, failing connections and configuration. Super Admins only.
 */
class SystemHealth extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Agency';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'System health';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.system-health')->viewData(fn (): array => ['checks' => app(SystemHealthChecks::class)->checks()]),
        ]);
    }
}
