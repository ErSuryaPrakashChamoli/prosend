<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Actions\Campaigns\CancelCampaign;
use App\Actions\Campaigns\LaunchCampaign;
use App\Actions\Campaigns\PauseCampaign;
use App\Actions\Campaigns\ResumeCampaign;
use App\Actions\Campaigns\RetryFailedCampaignMessages;
use App\Actions\Messages\SendTestEmail;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Widgets\CampaignProgress;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\ReadinessReport;
use App\Services\Sending\EmailPreviewer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
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
        return $this->record->status === CampaignStatus::Draft ? [] : [CampaignProgress::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->launchAction(),
            $this->campaignAction('pause', 'Pause', Heroicon::OutlinedPause, 'warning', PauseCampaign::class, 'Campaign paused', fn (Campaign $campaign): bool => $campaign->status->canBePaused()),
            $this->campaignAction('resume', 'Resume', Heroicon::OutlinedPlay, 'primary', ResumeCampaign::class, 'Campaign resumed', fn (Campaign $campaign): bool => $campaign->status === CampaignStatus::Paused),
            EditAction::make(),
            ActionGroup::make([
                $this->previewAction(),
                $this->sendTestAction(),
                $this->duplicateAction(),
                $this->campaignAction('retryFailures', 'Retry failed emails', Heroicon::OutlinedArrowPath, 'gray', RetryFailedCampaignMessages::class, 'Failed emails queued again', fn (Campaign $campaign): bool => in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed], true) && $campaign->messages()->where('status', MessageStatus::Failed)->where('is_retryable', true)->exists())
                    ->modalDescription('Only temporary and configuration failures are retried. Rejected addresses and emails with an unknown outcome are not, to avoid duplicates.'),
                $this->campaignAction('cancel', 'Cancel campaign', Heroicon::OutlinedXCircle, 'danger', CancelCampaign::class, 'Campaign cancelled', fn (Campaign $campaign): bool => $campaign->status->canBeCancelled())
                    ->modalDescription('Emails that have not been sent yet will not be sent. This cannot be undone.'),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    private function launchAction(): Action
    {
        return Action::make('launch')
            ->label(fn (): string => $this->record->scheduled_at?->isFuture() ? 'Schedule' : 'Launch')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (): bool => $this->record->status === CampaignStatus::Draft && auth()->user()->can('operate', $this->record))
            ->modalHeading('Review before sending')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->schema(fn (): array => $this->readinessSchema($this->readiness()))
            ->modalSubmitActionLabel(fn (): string => $this->record->scheduled_at?->isFuture()
                ? 'Schedule for '.$this->record->scheduled_at->toDayDateTimeString()
                : 'Send to '.Number::format($this->readiness()->eligibleCount).' recipients')
            ->modalSubmitAction(fn (Action $action) => $action->disabled(! $this->readiness()->canLaunch()))
            ->action(function (LaunchCampaign $launch): void {
                try {
                    $report = $launch->handle($this->record);
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
    private function readinessSchema(ReadinessReport $report): array
    {
        $schema = [];

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

        if ($report->canLaunch() && $report->eligibleCount >= config('prosend.campaigns.large_audience_threshold')) {
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

                ($message->status === MessageStatus::Accepted ? $notification->success() : $notification->danger())->send();
            });
    }

    private function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate as draft')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->visible(fn (): bool => CampaignResource::canCreate())
            ->action(function (): void {
                $copy = $this->record->replicate(['status', 'scheduled_at', 'started_at', 'completed_at', 'batch_id']);
                $copy->name = 'Copy of '.$this->record->name;
                $copy->status = CampaignStatus::Draft;
                $copy->created_by = auth()->id();
                $copy->save();

                $copy->lists()->sync($this->record->lists()->pluck('contact_lists.id'));
                $copy->selectedContacts()->sync($this->record->selectedContacts()->pluck('contacts.id'));

                $this->redirect(CampaignResource::getUrl('edit', ['record' => $copy]));
            });
    }
}
