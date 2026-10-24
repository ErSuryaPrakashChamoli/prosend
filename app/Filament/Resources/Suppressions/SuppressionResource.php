<?php

namespace App\Filament\Resources\Suppressions;

use App\Enums\SuppressionReason;
use App\Filament\Resources\Suppressions\Pages\ManageSuppressions;
use App\Models\Suppression;
use App\Services\AuditLogger;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Audiences';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Suppression list';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                Select::make('reason')
                    ->options(SuppressionReason::class)
                    ->default(SuppressionReason::Manual)
                    ->required(),
                Textarea::make('note')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('reason')
                    ->badge(),
                TextColumn::make('source')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('note')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Added by')
                    ->placeholder('System')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Suppressed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(SuppressionReason::class)
                    ->multiple(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove suppression?')
                    ->modalDescription('ProSend will be able to email this address again. Only do this if you are sure the address is valid and the person wants to receive email.')
                    ->after(fn (Suppression $record, AuditLogger $auditLogger) => $auditLogger->record('suppression.removed', $record, [
                        'email' => $record->email,
                        'reason' => $record->reason->value,
                    ])),
            ])
            ->emptyStateHeading('No suppressed addresses')
            ->emptyStateDescription('Hard bounces, spam complaints, and unsubscribes are added here automatically so they are never emailed again.')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppressions::route('/'),
        ];
    }
}
