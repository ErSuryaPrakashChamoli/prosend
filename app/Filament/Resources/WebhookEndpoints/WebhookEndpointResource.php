<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Enums\OutreachEvent;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Models\WebhookEndpoint;
use App\Services\Providers\SmtpHostPolicy;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * URLs that receive signed notifications of outreach events, for CRMs, Zapier-style tools and your own applications.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static ?string $modelLabel = 'webhook endpoint';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?string $recordTitleAttribute = 'url';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('url')
                    ->label('URL')
                    ->required()
                    ->url()
                    ->maxLength(2048)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $problem = static::urlProblem((string) $value);

                        if ($problem !== null) {
                            $fail($problem);
                        }
                    }),
                TextInput::make('description')
                    ->maxLength(255),
                CheckboxList::make('events')
                    ->options(OutreachEvent::class)
                    ->columns(2)
                    ->bulkToggleable()
                    ->required(),
                Toggle::make('is_enabled')
                    ->label('Send events')
                    ->default(true),
            ]);
    }

    /**
     * Webhooks only go to public HTTPS addresses (plain HTTP is allowed locally, for testing).
     */
    public static function urlProblem(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme !== 'https' && ! ($scheme === 'http' && app()->environment('local'))) {
            return 'Use an https:// address, so events are encrypted on the way.';
        }

        return app(SmtpHostPolicy::class)->problemWith((string) parse_url($url, PHP_URL_HOST), allowPrivate: (bool) config('prosend.webhooks.allow_private_hosts'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('url')->label('URL')->columnSpan(2)->copyable(),
                TextEntry::make('is_enabled')->label('Status')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Sending' : 'Turned off')->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('events')->badge()->formatStateUsing(fn (string $state): string => OutreachEvent::tryFrom($state)?->getLabel() ?? $state)->columnSpan(2),
                TextEntry::make('consecutive_failures')->label('Failures in a row')->helperText('Turned off automatically after '.WebhookEndpoint::DISABLE_AFTER_FAILURES.'.'),
                TextEntry::make('last_success_at')->label('Last delivered')->since()->placeholder('Never'),
                TextEntry::make('last_failure_at')->label('Last failed')->since()->placeholder('Never'),
                TextEntry::make('description')->placeholder('—'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')
                    ->label('URL')
                    ->description(fn (WebhookEndpoint $record): ?string => $record->description)
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (WebhookEndpoint $record): string => count($record->events ?? []).' event(s)'),
                IconColumn::make('is_enabled')
                    ->label('Sending')
                    ->boolean(),
                TextColumn::make('last_success_at')
                    ->label('Last delivered')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No webhooks')
            ->emptyStateDescription('Send replies, bounces, new prospects and other events to your CRM or automation tool.')
            ->emptyStateIcon(Heroicon::OutlinedArrowTopRightOnSquare);
    }

    public static function getRelations(): array
    {
        return [
            DeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'view' => ViewWebhookEndpoint::route('/{record}'),
        ];
    }
}
