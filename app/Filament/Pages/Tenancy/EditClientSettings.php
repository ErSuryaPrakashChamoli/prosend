<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\Permission;
use App\Models\Client;
use App\Services\AuditLogger;
use DateTimeZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The client's own settings, from the client menu: contact details, the postal address in its marketing footer, and the
 * defaults new campaigns start with.
 */
class EditClientSettings extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Client settings';
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof Client && (auth()->user()?->hasPermission(Permission::ManageSettings, $tenant) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->required(),
                        TextInput::make('website')->url()->maxLength(255),
                        TextInput::make('contact_email')->label('Contact email')->email()->maxLength(255),
                        Textarea::make('postal_address')
                            ->label('Postal address for marketing email')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Shown in the footer of this client\'s marketing email. Required for commercial email in the US (CAN-SPAM) and good practice everywhere. Without it, the installation\'s address is used.')
                            ->columnSpanFull(),
                    ]),
                Section::make('New campaign defaults')
                    ->description('New campaigns start with these; each campaign can change them.')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('default_send_days')
                            ->label('Sending days')
                            ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                            ->columns(7)
                            ->columnSpanFull(),
                        TimePicker::make('default_send_window_start')->label('From')->seconds(false)->format('H:i')->requiredWith('default_send_window_end'),
                        TimePicker::make('default_send_window_end')->label('Until')->seconds(false)->format('H:i')->requiredWith('default_send_window_start')->after('default_send_window_start'),
                        Toggle::make('default_track_opens')->label('Track opens'),
                        Toggle::make('default_track_clicks')->label('Track clicks'),
                    ]),
            ]);
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record('client.settings_changed', $this->tenant, ['changed' => array_keys($this->tenant->getChanges())]);
    }
}
