<?php

namespace App\Filament\Resources\FailedJobs;

use App\Filament\Resources\FailedJobs\Pages\ManageFailedJobs;
use App\Models\FailedJob;
use App\Services\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * Queue jobs that failed for good, with retry and forget. Super Admins only: jobs span every client.
 */
class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Agency';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Failed jobs';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('failed_at')->label('Failed')->dateTime()->sortable(),
                TextColumn::make('job')->state(fn (FailedJob $record): string => $record->jobName()),
                TextColumn::make('queue'),
                TextColumn::make('exception')->label('Error')->state(fn (FailedJob $record): string => $record->summary())->wrap(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('The job runs again from the start. Sending jobs never send an email twice: each email is claimed before it is handed over.')
                    ->action(fn (FailedJob $record) => static::retry(collect([$record]))),
                Action::make('forget')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record, AuditLogger $auditLogger): void {
                        Artisan::call('queue:forget', ['id' => $record->uuid]);
                        $auditLogger->record('failed_job.forgotten', null, ['job' => $record->jobName(), 'uuid' => $record->uuid]);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('retrySelected')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => static::retry($records)),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FailedJob>  $jobs
     */
    private static function retry(\Illuminate\Support\Collection $jobs): void
    {
        $uuids = $jobs->pluck('uuid')->all();
        Artisan::call('queue:retry', ['id' => $uuids]);
        app(AuditLogger::class)->record('failed_job.retried', null, ['uuids' => $uuids]);

        Notification::make()->title(count($uuids).' job(s) queued again')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFailedJobs::route('/'),
        ];
    }
}
