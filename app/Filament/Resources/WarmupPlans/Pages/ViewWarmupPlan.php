<?php

namespace App\Filament\Resources\WarmupPlans\Pages;

use App\Enums\WarmupStatus;
use App\Filament\Resources\WarmupPlans\WarmupPlanResource;
use App\Models\WarmupPlan;
use App\Services\AuditLogger;
use App\Services\Warmup\WarmupProgression;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property WarmupPlan $record
 */
class ViewWarmupPlan extends ViewRecord
{
    protected static string $resource = WarmupPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve and start')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => $this->record->status === WarmupStatus::Draft && WarmupPlanResource::canEdit($this->record))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'From now on, email covered by this plan is limited to '.$this->record->todaysLimit().' per day, rising only while results stay healthy.')
                ->action(fn (AuditLogger $auditLogger) => $this->changeStatus(WarmupStatus::Active, 'warmup.approved', $auditLogger, [
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'current_daily_volume' => $this->record->current_daily_volume ?? $this->record->initial_daily_volume,
                    'last_increased_at' => now(),
                ])),
            Action::make('pause')
                ->icon(Heroicon::OutlinedPause)
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === WarmupStatus::Active && WarmupPlanResource::canEdit($this->record))
                ->requiresConfirmation()
                ->modalDescription('Email covered by this plan waits until the plan is resumed.')
                ->action(fn (AuditLogger $auditLogger) => $this->changeStatus(WarmupStatus::Paused, 'warmup.paused', $auditLogger, ['paused_reason' => 'Paused by '.auth()->user()->name.'.'])),
            Action::make('resume')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $this->record->status === WarmupStatus::Paused && WarmupPlanResource::canEdit($this->record))
                ->requiresConfirmation()
                ->modalDescription('Only resume once the cause is fixed. Volume stays at the current step.')
                ->action(fn (AuditLogger $auditLogger) => $this->changeStatus(WarmupStatus::Active, 'warmup.resumed', $auditLogger, ['paused_reason' => null])),
            Action::make('evaluate')
                ->label('Review now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => in_array($this->record->status, WarmupStatus::enforcing(), true) && WarmupPlanResource::canEdit($this->record))
                ->action(function (WarmupProgression $progression): void {
                    $assessment = $progression->evaluate($this->record);

                    Notification::make()
                        ->title($assessment->recommendation->getLabel())
                        ->body('Daily limit is now '.$this->record->refresh()->todaysLimit().'.')
                        ->status($assessment->recommendation->getColor() === 'success' ? 'success' : 'warning')
                        ->send();

                    $this->redirect(WarmupPlanResource::getUrl('view', ['record' => $this->record]));
                }),
            EditAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function changeStatus(WarmupStatus $status, string $auditAction, AuditLogger $auditLogger, array $attributes = []): void
    {
        $this->record->forceFill(['status' => $status, ...$attributes])->save();
        $auditLogger->record($auditAction, $this->record);

        Notification::make()->success()->title('Plan '.$status->getLabel())->send();

        $this->redirect(WarmupPlanResource::getUrl('view', ['record' => $this->record]));
    }
}
