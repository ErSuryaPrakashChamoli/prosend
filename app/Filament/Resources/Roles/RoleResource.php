<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Roles and their permissions, shared by every client. People get a role per client on its Members page.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Agency';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Roles and permissions';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60)
                    ->unique(ignoreRecord: true),
                TextInput::make('description')
                    ->maxLength(255),
                CheckboxList::make('permissions')
                    ->helperText(fn (?Role $record): string => $record?->isAdmin()
                        ? 'Admin always has every permission, so each client keeps someone who can manage it.'
                        : 'What people with this role can do in the clients where they have it.')
                    ->options(collect(Permission::cases())->mapWithKeys(fn (Permission $permission): array => [$permission->value => $permission->getLabel()])->all())
                    ->descriptions(collect(Permission::cases())->mapWithKeys(fn (Permission $permission): array => [$permission->value => $permission->getDescription()])->all())
                    ->disabled(fn (?Role $record): bool => $record?->isAdmin() ?? false)
                    ->bulkToggleable()
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('memberships'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Role $record): ?string => $record->description)
                    ->searchable(),
                TextColumn::make('key')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Custom' : 'Built in')
                    ->placeholder('Custom')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info'),
                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(fn (Role $record): string => count($record->permissions ?? []).' of '.count(Permission::cases())),
                TextColumn::make('memberships_count')
                    ->label('People')
                    ->numeric(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }
}
