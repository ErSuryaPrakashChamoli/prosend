<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Actions\Messages\SendTestEmail;
use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Services\Sending\EmailPreviewer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * @property EmailTemplate $record
 */
class ViewEmailTemplate extends ViewRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading('Template preview')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (EmailPreviewer $previewer) {
                    $rendered = $previewer->render($this->record->subject, $this->record->body, $this->record->purpose, null, auth()->user()->email, $this->record->text_body);

                    return view('filament.email-preview', [
                        'subject' => $rendered->subject,
                        'html' => $previewer->html($rendered, null),
                    ]);
                }),
            EditAction::make(),
            ActionGroup::make([
                Action::make('sendTest')
                    ->label('Send test email')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->visible(fn (): bool => EmailTemplateResource::canEdit($this->record))
                    ->modalDescription(fn (): string => 'Sends a test copy to '.auth()->user()->email.'. Custom variables are empty in tests.')
                    ->schema([
                        Select::make('sender_identity_id')
                            ->label('From')
                            ->options(fn (): array => SenderIdentity::query()->usable()->pluck('email', 'id')->all())
                            ->default(fn (): ?int => SenderIdentity::query()->usable()->value('id'))
                            ->required(),
                    ])
                    ->action(function (array $data, SendTestEmail $sendTest): void {
                        try {
                            $message = $sendTest->handle([
                                'sender_identity_id' => $data['sender_identity_id'],
                                'subject' => $this->record->subject,
                                'body' => $this->record->body,
                                'text_body' => $this->record->text_body,
                            ], auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Test email not sent')->body(implode(' ', $exception->validator->errors()->all()))->send();

                            return;
                        }

                        $notification = Notification::make()->title('Test email: '.$message->status->getLabel())->body($message->error_message ?? 'Sent to '.$message->to_email.'.');

                        ($message->status === MessageStatus::Accepted ? $notification->success() : $notification->danger())->send();
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->visible(fn (): bool => EmailTemplateResource::canCreate())
                    ->action(fn () => $this->redirect(EmailTemplateResource::getUrl('edit', ['record' => $this->record->duplicate()]))),
                Action::make('archive')
                    ->label(fn (): string => $this->record->status === TemplateStatus::Archived ? 'Restore from archive' : 'Archive')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->visible(fn (): bool => EmailTemplateResource::canEdit($this->record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => $this->record->status === TemplateStatus::Archived
                        ? 'The template returns as a draft. Activate it to use it again.'
                        : 'Archived templates are kept, but cannot be chosen for new emails. Campaigns already using it are not affected.')
                    ->action(function (): void {
                        $this->record->update([
                            'status' => $this->record->status === TemplateStatus::Archived ? TemplateStatus::Draft : TemplateStatus::Archived,
                        ]);

                        $this->refreshFormData(['status']);
                    }),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }
}
