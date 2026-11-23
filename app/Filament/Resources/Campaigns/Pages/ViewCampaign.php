<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Actions\Campaigns\CancelCampaign;
use App\Actions\Campaigns\LaunchCampaign;
use App\Actions\Campaigns\PauseCampaign;
use App\Actions\Campaigns\ResumeCampaign;
use App\Actions\Campaigns\RetryFailedCampaignMessages;
use App\Actions\Campaigns\ReturnCampaignToDraft;
use App\Actions\Campaigns\SubmitCampaignForApproval;
use App\Actions\Messages\SendTestEmail;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Widgets\CampaignProgress;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Services\AuditLogger;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\ReadinessReport;
use App\Services\Sending\EmailPreviewer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * @property Campaign $record
 */
class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderWidgets(): array
    {
        return in_array($this->record->status, [CampaignStatus::Draft, CampaignStatus::PendingApproval], true) ? [] : [CampaignProgress::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->submitAction(),
            $this->launchAction(),
            $this->returnToDraftAction(),
            $this->campaignAction('pause', 'Pause', Heroicon::OutlinedPause, 'warning', PauseCampaign::class, 'Campaign paused', fn (Campaign $campaign): bool => $campaign->status->canBePaused()),
            $this->campaignAction('resume', 'Resume', Heroicon::OutlinedPlay, 'primary', ResumeCampaign::class, 'Campaign resumed', fn (Campaign $campaign): bool => $campaign->status === CampaignStatus::Paused),
            EditAction::make(),
            ActionGroup::make([
                $this->previewAction(),
                $this->sendTestAction(),
                $this->duplicateAction(),
                $this->campaignAction('retryFailures', 'Retry failed emails', Heroicon::OutlinedArrowPath, 'gray', RetryFailedCampaignMessages::class, 'Failed emails queued again', fn (Campaign $campaign): bool => in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed], true) && $campaign->messages()->where('status', MessageStatus::Failed)->where('is_retryable', true)->exists())
                    ->modalDescription('Only temporary and configuration failures are retried. Rejected addresses and emails with an unknown outcome are not, to avoid duplicates.'),
                $this->stopVersionAction(),
                $this->archiveAction(),
                $this->campaignAction('cancel', 'Stop campaign', Heroicon::OutlinedXCircle, 'danger', CancelCampaign::class, 'Campaign stopped', fn (Campaign $campaign): bool => $campaign->status->canBeCancelled())
                    ->modalDescription('Emails that have not been sent yet, including every pending follow-up, will not be sent. This cannot be undone.'),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    private function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Submit for approval')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (): bool => auth()->user()->can('submit', $this->record))
            ->modalHeading('Submit for approval')
            ->modalDescription('An administrator reviews and approves the campaign before anything is sent. It cannot be edited while it waits; return it to draft to make changes.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->schema(fn (): array => $this->readinessSchema($this->readiness(), confirmLargeAudience: false))
            ->modalSubmitActionLabel('Submit for approval')
            ->modalSubmitAction(fn (Action $action) => $action->disabled(! $this->readiness()->canLaunch()))
            ->action(function (SubmitCampaignForApproval $submit): void {
                try {
                    $submit->handle($this->record, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Campaign not submitted')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                Notification::make()->success()->title('Submitted for approval')->body('Administrators have been notified.')->send();

                $this->redirect(CampaignResource::getUrl('view', ['record' => $this->record]));
            });
    }

    private function returnToDraftAction(): Action
    {
        return Action::make('returnToDraft')
            ->label(fn (): string => $this->record->submitted_by === auth()->id() ? 'Withdraw' : 'Return to draft')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('returnToDraft', $this->record))
            ->modalDescription('The campaign becomes an editable draft again and must be submitted again before it can send.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason')
                    ->helperText('Shown on the campaign and sent to whoever submitted it.')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ReturnCampaignToDraft $returnToDraft): void {
                try {
                    $returnToDraft->handle($this->record, auth()->user(), $data['note'] ?? null);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Not possible')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                Notification::make()->success()->title('Campaign returned to draft')->send();

                $this->redirect(CampaignResource::getUrl('view', ['record' => $this->record]));
            });
    }

    private function launchAction(): Action
    {
        return Action::make('launch')
            ->label(fn (): string => $this->record->scheduled_at?->isFuture() ? 'Approve and schedule' : 'Approve and send')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (): bool => auth()->user()->can('approve', $this->record))
            ->modalHeading('Review before sending')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            // What the approver is shown is pinned, so the approval cannot apply to a later version.
            ->fillForm(fn (): array => [
                'reviewed_fingerprint' => $this->record->submissionFingerprint(),
                'reviewed_eligible_count' => $this->readiness()->eligibleCount,
            ])
            ->schema(fn (): array => [
                ...$this->readinessSchema($this->readiness()),
                Hidden::make('reviewed_fingerprint'),
                Hidden::make('reviewed_eligible_count'),
            ])
            ->modalSubmitActionLabel(fn (): string => $this->record->scheduled_at?->isFuture()
                ? 'Schedule for '.$this->record->scheduled_at->toDayDateTimeString()
                : 'Send to '.Number::format($this->readiness()->eligibleCount).' recipients')
            ->modalSubmitAction(fn (Action $action) => $action->disabled(! $this->readiness()->canLaunch()))
            ->action(function (array $data, LaunchCampaign $launch): void {
                try {
                    $report = $launch->handle($this->record, auth()->user(), (string) ($data['reviewed_fingerprint'] ?? ''), (int) ($data['reviewed_eligible_count'] ?? 0));
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Campaign not launched')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($this->record->status === CampaignStatus::Scheduled ? 'Campaign scheduled' : 'Campaign launched')
                    ->body(Number::format($report->eligibleCount).' emails will be sent at '.$this->record->send_rate_per_minute.' per minute. Keep a queue worker running.')
                    ->send();

                $this->refreshFormData(['status']);
                $this->redirect(CampaignResource::getUrl('view', ['record' => $this->record]));
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function readinessSchema(ReadinessReport $report, bool $confirmLargeAudience = true): array
    {
        $schema = [];

        if (filled($this->record->review_note)) {
            $schema[] = Callout::make($this->record->review_note)->info();
        }

        foreach ($report->blockingIssues as $issue) {
            $schema[] = Callout::make($issue)->danger()->icon(Heroicon::OutlinedExclamationTriangle);
        }

        foreach ($report->warnings as $warning) {
            $schema[] = Callout::make($warning)->warning();
        }

        $schema[] = Grid::make(2)->schema([
            TextEntry::make('eligible')->label('Will receive the email')->state(Number::format($report->eligibleCount))->weight('bold')->color('success'),
            TextEntry::make('audience')->label('Contacts in audience')->state(Number::format($report->audienceCount)),
            TextEntry::make('excluded')
                ->label('Skipped')
                ->state(Number::format($report->excludedCount()))
                ->helperText(sprintf(
                    '%s suppressed, %s unsubscribed, %s without marketing consent. A contact can count in more than one.',
                    Number::format($report->suppressedCount),
                    Number::format($report->unsubscribedCount),
                    Number::format($report->withoutConsentCount),
                )),
            TextEntry::make('missing_data')
                ->label('Missing required data')
                ->state(Number::format($report->missingDataCount))
                ->helperText('Eligible contacts without a value for a required variable. They are skipped.'),
            TextEntry::make('duplicates')
                ->label('In more than one list')
                ->state(Number::format($report->duplicateCount))
                ->helperText('Each contact receives the email once.'),
            TextEntry::make('duration')
                ->label('Estimated sending time')
                ->state($report->estimatedMinutes < 1 ? 'Under a minute' : Number::format($report->estimatedMinutes).' minutes'),
            TextEntry::make('quota')
                ->label('Provider sends remaining')
                ->state($report->remainingQuota === null ? 'No limit configured' : Number::format($report->remainingQuota)),
        ]);

        if ($confirmLargeAudience && $report->canLaunch() && $report->eligibleCount >= config('prosend.campaigns.large_audience_threshold')) {
            $schema[] = TextInput::make('confirmation')
                ->label('Type '.$report->eligibleCount.' to confirm sending to this many people')
                ->required()
                ->in([(string) $report->eligibleCount])
                ->validationMessages(['in' => 'Type the recipient count exactly to confirm.']);
        }

        return $schema;
    }

    private function readiness(): ReadinessReport
    {
        return once(fn (): ReadinessReport => app(CampaignReadiness::class)->assess($this->record));
    }

    /**
     * @param  class-string  $actionClass
     * @param  Closure(Campaign): bool  $isAvailable
     */
    private function campaignAction(string $name, string $label, Heroicon $icon, string $color, string $actionClass, string $successTitle, Closure $isAvailable): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => $isAvailable($this->record) && auth()->user()->can('operate', $this->record))
            ->requiresConfirmation()
            ->action(function () use ($actionClass, $successTitle): void {
                try {
                    app($actionClass)->handle($this->record);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Not possible')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                Notification::make()->success()->title($successTitle)->send();

                $this->redirect(CampaignResource::getUrl('view', ['record' => $this->record]));
            });
    }

    private function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading('Email preview')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (EmailPreviewer $previewer) {
                $rendered = $previewer->render(
                    (string) $this->record->subject,
                    (string) $this->record->body,
                    $this->record->purpose,
                    $this->record->senderIdentity,
                    auth()->user()->email,
                    $this->record->text_body,
                );

                return view('filament.email-preview', [
                    'subject' => $rendered->subject,
                    'html' => $previewer->html($rendered, $this->record->senderIdentity),
                ]);
            });
    }

    private function sendTestAction(): Action
    {
        return Action::make('sendTest')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedBeaker)
            ->visible(fn (): bool => auth()->user()->can('operate', $this->record))
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'A test copy is sent to '.auth()->user()->email.' right away. Merge tags use your account name.')
            ->action(function (SendTestEmail $sendTest): void {
                try {
                    $message = $sendTest->handle([
                        'sender_identity_id' => $this->record->sender_identity_id,
                        'subject' => $this->record->subject,
                        'body' => $this->record->body,
                        'text_body' => $this->record->text_body,
                    ], auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Test email not sent')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                $notification = Notification::make()->title('Test email: '.$message->status->getLabel())->body($message->error_message ?? $message->status->getDescription());

                (in_array($message->status, [MessageStatus::Accepted, MessageStatus::Captured], true) ? $notification->success() : $notification->danger())->send();
            });
    }

    /**
     * Stops giving an A/B version to emails not created yet, for example once another version has won. Emails that
     * already carry it are unaffected; version A cannot be stopped, so every prospect still gets an email.
     */
    private function stopVersionAction(): Action
    {
        return Action::make('stopVersion')
            ->label('Stop an A/B version')
            ->icon(Heroicon::OutlinedBeaker)
            ->visible(fn (): bool => auth()->user()->can('operate', $this->record) && $this->record->variants()->where('is_active', true)->exists())
            ->schema([
                Select::make('variant')
                    ->label('Version')
                    ->options(fn (): array => $this->record->variants()->with('step')->where('is_active', true)->get()
                        ->mapWithKeys(fn (CampaignVariant $variant): array => [$variant->getKey() => 'Version '.$variant->label().' of '.($variant->step === null ? 'the first email' : 'follow-up step '.$variant->step->stepNumber())])
                        ->all())
                    ->required(),
            ])
            ->modalDescription('Prospects who have not had this email yet get the remaining versions instead. Emails already created keep their version.')
            ->action(function (array $data, AuditLogger $auditLogger): void {
                $variant = $this->record->variants()->whereKey($data['variant'])->firstOrFail();
                $variant->forceFill(['is_active' => false])->save();
                $auditLogger->record('campaign.ab_version_stopped', $this->record, ['version' => $variant->label(), 'step' => $variant->step?->stepNumber() ?? 1]);

                Notification::make()->title("Version {$variant->label()} stopped")->success()->send();
            });
    }

    /**
     * Archived campaigns leave the default campaign list; their results stay readable.
     */
    private function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(fn (): string => $this->record->isArchived() ? 'Restore from archive' : 'Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->visible(fn (): bool => auth()->user()->can('archive', $this->record))
            ->action(function (AuditLogger $auditLogger): void {
                $archiving = ! $this->record->isArchived();
                $this->record->forceFill(['archived_at' => $archiving ? now() : null])->save();
                $auditLogger->record($archiving ? 'campaign.archived' : 'campaign.unarchived', $this->record);

                Notification::make()->title($archiving ? 'Campaign archived' : 'Campaign restored')->success()->send();
            });
    }

    private function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate as draft')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->visible(fn (): bool => CampaignResource::canCreate())
            ->action(function (): void {
                $copy = $this->record->replicate(['status', 'scheduled_at', 'started_at', 'prepared_at', 'completed_at', 'archived_at', 'batch_id', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approved_recipient_count', 'review_note']);
                $copy->name = 'Copy of '.$this->record->name;
                $copy->status = CampaignStatus::Draft;
                $copy->created_by = auth()->id();
                $copy->save();

                $copy->lists()->sync($this->record->lists()->pluck('contact_lists.id'));
                $copy->selectedContacts()->sync($this->record->selectedContacts()->pluck('contacts.id'));

                // Stopped versions are not copied: the form cannot show them, and a new campaign starts every version afresh.
                foreach ($this->record->firstEmailVariants->where('is_active', true) as $variant) {
                    $copy->firstEmailVariants()->create($variant->only(['position', 'weight', 'subject', 'body', 'text_body', 'is_active']));
                }

                foreach ($this->record->steps as $step) {
                    $stepCopy = $copy->steps()->create($step->only(['position', 'wait_days', 'condition', 'subject', 'body', 'text_body']));

                    foreach ($step->variants->where('is_active', true) as $variant) {
                        $stepCopy->variants()->create($variant->only(['position', 'weight', 'subject', 'body', 'text_body', 'is_active']));
                    }
                }

                $this->redirect(CampaignResource::getUrl('edit', ['record' => $copy]));
            });
    }
}
