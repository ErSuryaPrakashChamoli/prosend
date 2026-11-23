<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Inbox\ConversationManager;
use App\Services\Inbox\ConversationReplier;
use App\Services\Inbox\ConversationThread;
use App\Services\Sending\EmailContentRenderer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One conversation: the whole thread, internal notes, and the reply, status, assignment and note actions.
 */
class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! $this->getConversation()->is_read && auth()->user()->can('update', $this->getConversation())) {
            app(ConversationManager::class)->update($this->getConversation(), ['is_read' => true]);
        }
    }

    public function getTitle(): string
    {
        return $this->getConversation()->subject;
    }

    private function getConversation(): Conversation
    {
        /** @var Conversation */
        return $this->record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Thread')
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('thread')
                            ->hiddenLabel()
                            ->state(fn (Conversation $record): array => app(ConversationThread::class)->for($record))
                            ->schema([
                                TextEntry::make('kind')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->color(fn (string $state): string => str_starts_with($state, 'Reply') ? 'success' : (str_starts_with($state, 'Automatic') ? 'gray' : 'info')),
                                TextEntry::make('meta')
                                    ->hiddenLabel()
                                    ->color('gray')
                                    ->size('sm'),
                                TextEntry::make('subject')
                                    ->hiddenLabel()
                                    ->weight('bold'),
                                TextEntry::make('body')
                                    ->hiddenLabel()
                                    ->extraAttributes(['style' => 'white-space: pre-wrap;']),
                                TextEntry::make('attachments')
                                    ->label('Attachments')
                                    ->badge()
                                    ->color('gray')
                                    ->visible(fn (?array $state): bool => filled($state)),
                            ]),
                    ]),
                Section::make('Details')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('contact.email')
                            ->label('Prospect')
                            ->url(fn (Conversation $record): ?string => $record->contact && auth()->user()->can('view', $record->contact) ? ContactResource::getUrl('view', ['record' => $record->contact]) : null)
                            ->helperText(fn (Conversation $record): ?string => $record->contact?->company)
                            ->placeholder('Unknown sender'),
                        TextEntry::make('campaign.name')
                            ->label('Campaign')
                            ->placeholder('Individual email'),
                        TextEntry::make('assignee.name')
                            ->label('Assigned to')
                            ->placeholder('Unassigned'),
                        TextEntry::make('senderIdentity.email')
                            ->label('Replies are sent from')
                            ->placeholder('—'),
                        TextEntry::make('inboundMailbox.email')
                            ->label('Received at')
                            ->placeholder('—'),
                        RepeatableEntry::make('notes')
                            ->label('Internal notes')
                            ->schema([
                                TextEntry::make('body')
                                    ->hiddenLabel()
                                    ->helperText(fn ($record): string => ($record->author?->name ?? 'Someone').' · '.$record->created_at?->diffForHumans())
                                    ->extraAttributes(['style' => 'white-space: pre-wrap;']),
                            ])
                            ->placeholder('No notes yet'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $canUpdate = fn (): bool => auth()->user()->can('update', $this->getConversation());

        return [
            Action::make('reply')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible($canUpdate)
                ->modalHeading(fn (): string => 'Reply to '.($this->getConversation()->contact?->email ?? 'the prospect'))
                ->modalSubmitActionLabel('Send reply')
                ->schema([
                    RichEditor::make('body')
                        ->label('Message')
                        ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                        ->fileAttachments(false)
                        ->required(),
                ])
                ->action(function (array $data, ConversationReplier $replier): void {
                    try {
                        $replier->reply($this->getConversation(), (string) $data['body'], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Reply not sent')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

                        return;
                    }

                    Notification::make()->title('Reply queued')->success()->send();
                }),
            Action::make('status')
                ->label('Set status')
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->visible($canUpdate)
                ->fillForm(fn (): array => ['status' => $this->getConversation()->status])
                ->schema([
                    Select::make('status')->options(ConversationStatus::class)->required(),
                ])
                ->action(function (array $data, ConversationManager $manager): void {
                    $manager->update($this->getConversation(), ['status' => $data['status']]);
                    Notification::make()->title('Status updated')->success()->send();
                }),
            Action::make('assign')
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()->can('assign', $this->getConversation()))
                ->fillForm(fn (): array => ['assigned_to' => $this->getConversation()->assigned_to])
                ->schema([
                    Select::make('assigned_to')
                        ->label('Assign to')
                        ->options(fn (): array => User::query()->memberOf()->orderBy('name')->get()
                            ->filter(fn (User $user): bool => $user->hasPermission(Permission::ViewInbox))
                            ->pluck('name', 'id')
                            ->all())
                        ->placeholder('Nobody')
                        ->searchable(),
                ])
                ->action(function (array $data, ConversationManager $manager): void {
                    $manager->assign($this->getConversation(), filled($data['assigned_to'] ?? null) ? User::query()->memberOf()->findOrFail($data['assigned_to']) : null, auth()->user());
                    Notification::make()->title('Assignment updated')->success()->send();
                }),
            ActionGroup::make([
                Action::make('note')
                    ->label('Add internal note')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible($canUpdate)
                    ->schema([
                        Textarea::make('body')->label('Note')->required()->maxLength(5000)->rows(4),
                    ])
                    ->action(function (array $data, ConversationManager $manager): void {
                        $manager->addNote($this->getConversation(), auth()->user(), $data['body']);
                        Notification::make()->title('Note added')->success()->send();
                    }),
                Action::make('star')
                    ->label(fn (): string => $this->getConversation()->is_starred ? 'Remove star' : 'Star')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible($canUpdate)
                    ->action(fn (ConversationManager $manager) => $manager->update($this->getConversation(), ['is_starred' => ! $this->getConversation()->is_starred])),
                Action::make('markUnread')
                    ->label('Mark as unread')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible($canUpdate)
                    ->action(function (ConversationManager $manager): void {
                        $manager->update($this->getConversation(), ['is_read' => false]);
                        $this->redirect(ConversationResource::getUrl());
                    }),
                Action::make('download')
                    ->label('Download an attachment')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (): bool => $this->attachmentOptions() !== [])
                    ->schema([
                        Select::make('attachment')->options(fn (): array => $this->attachmentOptions())->required(),
                    ])
                    ->action(fn (array $data): ?StreamedResponse => $this->download((string) $data['attachment'])),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /**
     * @return array<string, string> "message id:index" => file name
     */
    private function attachmentOptions(): array
    {
        return $this->getConversation()->messages()->whereNotNull('attachments')->get()
            ->flatMap(fn (ConversationMessage $message): array => collect($message->attachments ?? [])
                ->mapWithKeys(fn (array $attachment, int $index): array => ["{$message->getKey()}:{$index}" => $attachment['name']])
                ->all())
            ->all();
    }

    /**
     * Attachments are served only through this page, to people who may view the conversation, and always as a download.
     */
    private function download(string $choice): ?StreamedResponse
    {
        [$messageId, $index] = array_map(intval(...), explode(':', $choice) + [0, 0]);
        $attachment = $this->getConversation()->messages()->whereKey($messageId)->first()?->attachments[$index] ?? null;

        if ($attachment === null) {
            return null;
        }

        return Storage::disk(config('prosend.attachments.disk'))->download($attachment['path'], $attachment['name'], ['Content-Type' => 'application/octet-stream']);
    }
}
