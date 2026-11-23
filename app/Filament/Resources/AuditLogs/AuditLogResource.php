<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use App\Models\Scopes\ClientScope;
use App\Services\Tenancy\CurrentClient;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit logs';

    protected static ?string $recordTitleAttribute = 'action';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Super Admins also see entries about the agency itself (clients, roles, people), which belong to no client.
     *
     * @return Builder<AuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->isSuperAdmin()) {
            return $query;
        }

        return $query
            ->withoutGlobalScope(ClientScope::class)
            ->where(fn (Builder $entries) => $entries->where('client_id', app(CurrentClient::class)->id())->orWhereNull('client_id'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime(),
                TextEntry::make('user.name')
                    ->label('By')
                    ->placeholder('System'),
                TextEntry::make('action')
                    ->badge(),
                TextEntry::make('subject_type')
                    ->label('Record')
                    ->formatStateUsing(fn (AuditLog $record): string => class_basename($record->subject_type ?? '').' #'.$record->subject_id)
                    ->placeholder('—'),
                TextEntry::make('ip_address')
                    ->label('IP address')
                    ->placeholder('—'),
                KeyValueEntry::make('properties')
                    ->label('Details')
                    ->state(fn (AuditLog $record): array => collect($record->properties ?? [])
                        ->map(fn (mixed $value): string => is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value))
                        ->all())
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System'),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('client_id')
                    ->label('Scope')
                    ->formatStateUsing(fn (): string => 'This client')
                    ->placeholder('Agency')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
                TextColumn::make('subject_type')
                    ->label('Record')
                    ->formatStateUsing(fn (AuditLog $record): string => class_basename($record->subject_type ?? '').' #'.$record->subject_id)
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => static::getEloquentQuery()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                SelectFilter::make('user')
                    ->relationship('user', 'name', fn (Builder $query) => auth()->user()?->isSuperAdmin() ? $query : $query->memberOf()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
