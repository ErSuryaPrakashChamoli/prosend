<?php

namespace App\Filament\Resources\Clients;

use App\Actions\Clients\SetClientActive;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Models\Client;
use App\Models\Scopes\ClientScope;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The agency's clients. Only Super Admins see this page; each client's own data is reached by switching to it.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Agency';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('URL name')
                    ->helperText('Appears in the panel address, as /admin/{URL name}. Leave empty to create it from the name.')
                    ->alphaDash()
                    ->maxLength(60)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                Select::make('timezone')
                    ->label('Time zone')
                    ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                    ->searchable()
                    ->default(config('app.timezone'))
                    ->required()
                    ->helperText('The default for campaign sending windows and reports.'),
                TextInput::make('website')
                    ->url()
                    ->maxLength(255),
                TextInput::make('contact_email')
                    ->label('Contact email')
                    ->email()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Counts span clients, so the client scope (which limits them to the client being viewed) is removed.
        $acrossClients = fn (Builder $query): Builder => $query->withoutGlobalScope(ClientScope::class);

        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'memberships',
                'contacts' => $acrossClients,
                'campaigns' => $acrossClients,
                'smtpProviders' => $acrossClients,
            ]))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Client $record): string => "/admin/{$record->slug}")
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('memberships_count')
                    ->label('Members')
                    ->numeric(),
                TextColumn::make('contacts_count')
                    ->label('Prospects')
                    ->numeric(),
                TextColumn::make('campaigns_count')
                    ->label('Campaigns')
                    ->numeric(),
                TextColumn::make('smtp_providers_count')
                    ->label('Mailbox providers')
                    ->numeric(),
                TextColumn::make('timezone')
                    ->label('Time zone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->url(fn (Client $record): string => Dashboard::getUrl(tenant: $record)),
                EditAction::make(),
                Action::make('deactivate')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('danger')
                    ->visible(fn (Client $record): bool => $record->is_active && static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalDescription('Members lose access, its API keys stop working, and follow-ups wait until it is active again. Emails already queued still go out; pause its campaigns first if they must stop. Super Admins can still open the client and activate it again.')
                    ->action(function (Client $record, SetClientActive $setActive): void {
                        $setActive->handle($record, false);
                        Notification::make()->title("{$record->name} deactivated")->success()->send();
                    }),
                Action::make('activate')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->visible(fn (Client $record): bool => ! $record->is_active && static::canEdit($record))
                    ->action(function (Client $record, SetClientActive $setActive): void {
                        $setActive->handle($record, true);
                        Notification::make()->title("{$record->name} activated")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClients::route('/'),
        ];
    }
}
