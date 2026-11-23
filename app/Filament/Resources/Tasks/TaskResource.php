<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Follow-up work for the team, created by hand or by automation rules.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * @return Builder<Task>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $due = static::getEloquentQuery()->where('assigned_to', auth()->id())->whereNull('completed_at')->where('due_at', '<=', now()->endOfDay())->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Your tasks due today or overdue';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('contact_id')
                    ->label('Prospect')
                    ->relationship('contact', 'email')
                    ->searchable(['email', 'first_name', 'last_name']),
                Select::make('assigned_to')
                    ->label('For')
                    ->options(fn (): array => User::query()->memberOf()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->id())
                    ->searchable(),
                DateTimePicker::make('due_at')
                    ->label('Due')
                    ->seconds(false),
                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'assignee', 'automationRule']))
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')
                    ->description(fn (Task $record): ?string => $record->automationRule ? "From automation: {$record->automationRule->name}" : null)
                    ->searchable()
                    ->color(fn (Task $record): ?string => $record->isDone() ? 'gray' : null),
                TextColumn::make('contact.email')
                    ->label('Prospect')
                    ->placeholder('—'),
                TextColumn::make('assignee.name')
                    ->label('For')
                    ->placeholder('Unassigned'),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (Task $record): ?string => ! $record->isDone() && $record->due_at?->isPast() ? 'danger' : null)
                    ->placeholder('—'),
                TextColumn::make('completed_at')
                    ->label('Done')
                    ->since()
                    ->placeholder('Open'),
            ])
            ->filters([
                TernaryFilter::make('completed')
                    ->label('Done')
                    ->placeholder('Open tasks')
                    ->trueLabel('Done')
                    ->falseLabel('All tasks')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('completed_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('completed_at'),
                    ),
                SelectFilter::make('assigned_to')
                    ->label('For')
                    ->options(fn (): array => User::query()->memberOf()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label(fn (Task $record): string => $record->isDone() ? 'Reopen' : 'Done')
                    ->icon(fn (Task $record): Heroicon => $record->isDone() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheck)
                    ->visible(fn (Task $record): bool => auth()->user()->can('update', $record))
                    ->action(fn (Task $record) => $record->forceFill(['completed_at' => $record->isDone() ? null : now()])->save()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No open tasks')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTasks::route('/'),
        ];
    }
}
