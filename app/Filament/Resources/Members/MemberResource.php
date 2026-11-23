<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ManageMembers;
use App\Models\ClientMembership;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The people who work in the current client and their roles. Super Admins reach every client without a membership.
 */
class MemberResource extends Resource
{
    protected static ?string $model = ClientMembership::class;

    protected static ?string $modelLabel = 'member';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $slug = 'members';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role_id')
                    ->label('Role')
                    ->options(fn (): array => static::assignableRoleOptions())
                    ->required(),
            ]);
    }

    /**
     * Roles the signed-in person may give: none with more permissions than their own.
     *
     * @return array<int, string>
     */
    public static function assignableRoleOptions(): array
    {
        return Role::query()->orderBy('id')->get()
            ->filter(fn (Role $role): bool => auth()->user()->canAssignRole($role))
            ->pluck('name', 'id')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'role', 'inviter']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->description(fn (ClientMembership $record): string => $record->user->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('role.name')
                    ->label('Role')
                    ->badge()
                    ->tooltip(fn (ClientMembership $record): ?string => $record->role->description),
                TextColumn::make('inviter.name')
                    ->label('Added by')
                    ->placeholder('Migrated'),
                TextColumn::make('created_at')
                    ->label('Member since')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role_id')
                    ->label('Role')
                    ->relationship('role', 'name'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Change role')
                    ->modalHeading(fn (ClientMembership $record): string => "Change {$record->user->name}'s role"),
                DeleteAction::make()
                    ->label('Remove')
                    ->modalDescription('They lose access to this client immediately. Their account and anything they created stay.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMembers::route('/'),
        ];
    }
}
