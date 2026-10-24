<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
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
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                SelectFilter::make('user')
                    ->relationship('user', 'name'),
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
