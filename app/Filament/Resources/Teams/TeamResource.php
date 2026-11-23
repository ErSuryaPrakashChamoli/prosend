<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Models\Team;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Groups of the client's members, such as sales pods. Leaders and members can only be people who work in the client.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true),
                TextInput::make('description')
                    ->maxLength(255),
                Select::make('leader_id')
                    ->label('Team leader')
                    ->options(fn (): array => static::clientMembers()->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('members')
                    ->relationship('members', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('users.id', static::clientMembers()->select('users.id')))
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ]);
    }

    /**
     * People with a membership in the current client.
     *
     * @return Builder<User>
     */
    public static function clientMembers(): Builder
    {
        return User::query()->memberOf()->orderBy('name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('leader')->withCount('members'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Team $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('leader.name')
                    ->label('Leader')
                    ->placeholder('—'),
                TextColumn::make('members_count')
                    ->label('Members')
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
            'index' => ManageTeams::route('/'),
        ];
    }
}
