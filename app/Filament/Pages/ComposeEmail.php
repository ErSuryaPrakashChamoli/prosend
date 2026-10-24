<?php

namespace App\Filament\Pages;

use App\Actions\Messages\QueueComposedEmails;
use App\Actions\Messages\SendTestEmail;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\EmailPreviewer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ComposeEmail extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Email Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Compose email';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', EmailMessage::class) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Section::make('Recipients')
                    ->columnSpan(2)
                    ->schema([
                        TagsInput::make('to')
                            ->label('To')
                            ->placeholder('Type an address and press Enter')
                            ->splitKeys(['Tab', ',', ' '])
                            ->nestedRecursiveRules(['email:rfc'])
                            ->required()
                            ->live()
                            ->helperText('Each address receives its own email, so recipients never see each other.'),
                        TagsInput::make('cc')
                            ->label('CC')
                            ->splitKeys(['Tab', ',', ' '])
                            ->nestedRecursiveRules(['email:rfc'])
                            ->helperText('CC and BCC are only available when sending to one address.'),
                        TagsInput::make('bcc')
                            ->label('BCC')
                            ->splitKeys(['Tab', ',', ' '])
                            ->nestedRecursiveRules(['email:rfc']),
                    ]),
                Section::make('Sending')
                    ->columnSpan(1)
                    ->schema([
                        Radio::make('purpose')
                            ->label('Email type')
                            ->options(EmailPurpose::class)
                            ->required()
                            ->live(),
                        Select::make('sender_identity_id')
                            ->label('From')
                            ->options(fn (): array => SenderIdentity::query()->usable()->orderBy('email')->get()
                                ->mapWithKeys(fn (SenderIdentity $identity): array => [$identity->getKey() => "{$identity->name} <{$identity->email}>".($identity->isVerified() ? '' : ' (unverified)')])
                                ->all())
                            ->required()
                            ->live(),
                        TextInput::make('reply_to')
                            ->label('Reply-To')
                            ->email()
                            ->placeholder('Sender default'),
                        DateTimePicker::make('scheduled_at')
                            ->label('Send later')
                            ->seconds(false)
                            ->minDate(now()->startOfMinute())
                            ->live()
                            ->helperText('Leave empty to send now.'),
                        TextEntry::make('summary')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => $this->summary($get('to'), $get('sender_identity_id'))),
                    ]),
                Section::make('Message')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('email_template_id')
                            ->label('Template')
                            ->options(fn (Get $get): array => EmailTemplate::query()->usableFor($this->purpose($get('purpose')))->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('No template')
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $template = EmailTemplate::query()->find($state);

                                if ($template === null) {
                                    return;
                                }

                                $set('subject', $template->subject);
                                $set('body', RichContentRenderer::make($template->body)->toArray());
                                $set('text_body', $template->text_body);
                            })
                            ->helperText('Choosing a template replaces the subject and content.'),
                        TextInput::make('subject')
                            ->required()
                            ->maxLength(255),
                        RichEditor::make('body')
                            ->label('Content')
                            ->required()
                            ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                            ->fileAttachments(false)
                            ->extraInputAttributes(['style' => 'min-height: 16rem;']),
                        Textarea::make('text_body')
                            ->label('Plain-text version')
                            ->rows(4)
                            ->helperText('Optional. When empty, a plain-text version is generated from the content.'),
                        FileUpload::make('attachments')
                            ->multiple()
                            ->disk(config('prosend.attachments.disk'))
                            ->directory(config('prosend.attachments.directory'))
                            ->visibility('private')
                            ->maxFiles(config('prosend.attachments.max_files'))
                            ->maxSize(config('prosend.attachments.max_file_size_kb'))
                            ->acceptedFileTypes(config('prosend.attachments.accepted_mime_types'))
                            ->storeFileNamesIn('attachment_names')
                            ->helperText('Up to '.config('prosend.attachments.max_files').' files, '.Number::fileSize(config('prosend.attachments.max_file_size_kb') * 1024).' each. PDF, images, text, CSV, Word, and Excel files.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('send')
                    ->footer([
                        Actions::make([
                            Action::make('send')
                                ->label(fn (): string => filled($this->data['scheduled_at'] ?? null) ? 'Schedule' : 'Send')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->submit('send')
                                ->keyBindings(['mod+enter']),
                        ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading('Email preview')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (EmailPreviewer $previewer) {
                    $senderIdentity = SenderIdentity::query()->find($this->data['sender_identity_id'] ?? null);
                    $rendered = $previewer->render(
                        (string) ($this->data['subject'] ?? ''),
                        $this->data['body'] ?? '',
                        $this->purpose(),
                        $senderIdentity,
                        auth()->user()->email,
                        $this->data['text_body'] ?? null,
                    );

                    return view('filament.email-preview', [
                        'subject' => $rendered->subject,
                        'html' => $previewer->html($rendered, $senderIdentity),
                    ]);
                }),
            Action::make('sendTest')
                ->label('Send test to me')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->action(function (SendTestEmail $sendTest): void {
                    try {
                        $message = $sendTest->handle([
                            'sender_identity_id' => $this->data['sender_identity_id'] ?? null,
                            'subject' => $this->data['subject'] ?? null,
                            'body' => $this->bodyHtml(),
                            'text_body' => $this->data['text_body'] ?? null,
                        ], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Test email not sent')->body(implode(' ', $exception->validator->errors()->all()))->send();

                        return;
                    }

                    $notification = Notification::make()
                        ->title('Test email: '.$message->status->getLabel())
                        ->body($message->error_message ?? 'Sent to '.$message->to_email.'.');

                    ($message->status === MessageStatus::Accepted ? $notification->success() : $notification->danger())->send();
                }),
        ];
    }

    public function send(QueueComposedEmails $queueComposedEmails): void
    {
        $data = $this->form->getState();

        try {
            $messages = $queueComposedEmails->handle($data, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.Str::before($key, '.') => $messages])
                    ->all(),
            );
        }

        $scheduledAt = $messages->first()?->scheduled_at;

        Notification::make()
            ->success()
            ->title($scheduledAt ? 'Email scheduled' : 'Email queued')
            ->body(trans_choice('{1} 1 email|[2,*] :count emails', $messages->count(), ['count' => $messages->count()])
                .($scheduledAt ? ' will be sent '.$scheduledAt->toDayDateTimeString().'.' : ' will be sent in a moment.')
                .' Track delivery under Email activity.')
            ->send();

        $this->form->fill($this->defaults());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'to' => array_values(array_filter([request()->query('to')], 'is_string')),
            'cc' => [],
            'bcc' => [],
            'purpose' => EmailPurpose::Transactional->value,
            'sender_identity_id' => SenderIdentity::query()->usable()->orderBy('id')->value('id'),
        ];
    }

    private function purpose(mixed $purpose = null): EmailPurpose
    {
        $purpose ??= $this->data['purpose'] ?? null;

        return $purpose instanceof EmailPurpose ? $purpose : (EmailPurpose::tryFrom((string) $purpose) ?? EmailPurpose::Transactional);
    }

    /**
     * The editor keeps unsaved content as a JSON document; sending needs HTML.
     */
    private function bodyHtml(): string
    {
        $body = $this->data['body'] ?? '';

        return is_array($body) ? RichContentRenderer::make($body)->toUnsafeHtml() : (string) $body;
    }

    /**
     * @param  list<string>|null  $to
     */
    private function summary(?array $to, mixed $senderIdentityId): string
    {
        $count = count($to ?? []);
        $provider = SenderIdentity::query()->find($senderIdentityId)?->smtpProvider;
        $remaining = $provider?->remainingQuota();

        return trans_choice('{0} No recipients yet|{1} 1 email will be sent|[2,*] :count emails will be sent', $count, ['count' => $count])
            .($remaining !== null ? '. '.Number::format($remaining).' sends left on '.$provider->name.'.' : '.');
    }
}
