<?php

namespace App\Filament\Resources\SmtpProviders\RelationManagers;

use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Models\EmailMessageAttempt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every hand-over to this provider, including failed ones, so connection and rejection problems can be
 * reviewed. Error summaries never contain credentials.
 */
class SendAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'sendAttempts';

    protected static ?string $title = 'Send attempts';

    private const OUTCOMES = [
        EmailMessageAttempt::OUTCOME_ACCEPTED => 'Accepted',
        EmailMessageAttempt::OUTCOME_CAPTURED => 'Captured (sandbox)',
        EmailMessageAttempt::OUTCOME_DEFERRED => 'Temporary error',
        EmailMessageAttempt::OUTCOME_FAILED => 'Failed',
        EmailMessageAttempt::OUTCOME_AMBIGUOUS => 'Outcome unknown',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('emailMessage'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('emailMessage.to_email')
                    ->label('Recipient')
                    ->url(fn (EmailMessageAttempt $record): ?string => $record->emailMessage ? EmailMessageResource::getUrl('view', ['record' => $record->emailMessage]) : null),
                TextColumn::make('attempt')
                    ->numeric(),
                TextColumn::make('outcome')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::OUTCOMES[$state] ?? 'In progress')
                    ->color(fn (?string $state): string => match ($state) {
                        EmailMessageAttempt::OUTCOME_ACCEPTED => 'primary',
                        EmailMessageAttempt::OUTCOME_DEFERRED, EmailMessageAttempt::OUTCOME_AMBIGUOUS => 'warning',
                        EmailMessageAttempt::OUTCOME_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('In progress'),
                TextColumn::make('code')
                    ->label('SMTP code')
                    ->placeholder('—'),
                TextColumn::make('summary')
                    ->label('Error')
                    ->limit(120)
                    ->tooltip(fn (EmailMessageAttempt $record): ?string => $record->summary)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->options(self::OUTCOMES),
            ])
            ->emptyStateHeading('No send attempts yet');
    }
}
