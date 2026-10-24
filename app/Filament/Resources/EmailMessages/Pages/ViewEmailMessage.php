<?php

namespace App\Filament\Resources\EmailMessages\Pages;

use App\Actions\Messages\RetryEmailMessage;
use App\Enums\MessageStatus;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Models\EmailMessage;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\EmailPreviewer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * @property EmailMessage $record
 */
class ViewEmailMessage extends ViewRecord
{
    protected static string $resource = EmailMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => RetryEmailMessage::canRetry($this->record) && auth()->user()->can('retry', $this->record))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->record->status === MessageStatus::Unknown
                    ? 'The provider may already have accepted this email. Retrying could send it twice.'
                    : 'The email is queued for another attempt.')
                ->action(function (RetryEmailMessage $retry): void {
                    $retry->handle($this->record);

                    Notification::make()->success()->title('Email queued again')->send();

                    $this->redirect(EmailMessageResource::getUrl('view', ['record' => $this->record]));
                }),
            Action::make('preview')
                ->label('View content')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading('Email content')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (EmailContentRenderer $renderer, EmailPreviewer $previewer) {
                    $rendered = $renderer->render($this->record);

                    return view('filament.email-preview', [
                        'subject' => $rendered->subject,
                        'html' => $previewer->html($rendered, $this->record->senderIdentity),
                    ]);
                }),
        ];
    }
}
