<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Agency';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Radio::make('role')
                    ->label('Access')
                    ->options([
                        UserRole::Admin->value => UserRole::Admin->getLabel(),
                        UserRole::Viewer->value => UserRole::Viewer->getLabel(),
                    ])
                    ->descriptions([
                        UserRole::Admin->value => UserRole::Admin->getDescription(),
                        UserRole::Viewer->value => UserRole::Viewer->getDescription(),
                    ])
                    // Accounts from before clients existed may still say "sender"; both mean member.
                    ->formatStateUsing(fn (UserRole|string|null $state): string => ($state instanceof UserRole ? $state : UserRole::tryFrom((string) $state)) === UserRole::Admin ? UserRole::Admin->value : UserRole::Viewer->value)
                    ->default(UserRole::Viewer->value)
                    ->required()
                    ->live()
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): ?string => $record?->is(auth()->user()) ? 'You cannot change your own access.' : null)
                    ->columnSpanFull(),
                Repeater::make('memberships')
                    ->label('Client access')
                    ->relationship()
                    ->schema([
                        Select::make('client_id')
                            ->label('Client')
                            ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                            // A membership never moves to another client; remove it and add a new one instead.
                            ->disabled(fn (?ClientMembership $record): bool => $record?->exists ?? false)
                            ->required()
                            ->distinct()
                            ->searchable(),
                        Select::make('role_id')
                            ->label('Role')
                            ->options(fn (): array => Role::query()->orderBy('id')->pluck('name', 'id')->all())
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add client')
                    ->hidden(fn (Get $get): bool => $get('role') === UserRole::Admin->value)
                    ->columnSpanFull(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->rule(Password::defaults())
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave empty to keep the current password.' : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Access')
                    ->badge()
                    ->tooltip(fn (User $record): string => $record->role->getDescription()),
                TextColumn::make('clients.name')
                    ->label('Clients')
                    ->badge()
                    ->color('gray')
                    ->limitList(3)
                    ->placeholder(fn (User $record): string => $record->isSuperAdmin() ? 'All clients' : 'None'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->relationship('clients', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
