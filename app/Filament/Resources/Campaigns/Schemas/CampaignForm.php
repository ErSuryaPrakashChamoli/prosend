<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\AudienceType;
use App\Enums\EmailPurpose;
use App\Enums\StepCondition;
use App\Models\CampaignVariant;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Services\Campaigns\AbTestResults;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\TemplateVariables;
use App\Services\Tenancy\CurrentClient;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Campaign')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Only visible to your team.'),
                        Radio::make('purpose')
                            ->options(EmailPurpose::class)
                            ->default(EmailPurpose::Marketing)
                            ->required()
                            ->live(),
                    ]),
                Section::make('Delivery')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('send_rate_per_minute')
                            ->label('Emails per minute')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(config('prosend.campaigns.max_send_rate_per_minute'))
                            ->default(config('prosend.campaigns.default_send_rate_per_minute'))
                            ->required()
                            ->helperText('A steady, moderate rate protects your sending reputation.'),
                        DateTimePicker::make('scheduled_at')
                            ->label('Send at')
                            ->seconds(false)
                            ->minDate(now()->startOfMinute())
                            ->helperText('Leave empty to start sending as soon as an administrator approves it.'),
                    ]),
                Section::make('Audience')
                    ->description('Suppressed and unsubscribed contacts, and contacts without consent for marketing, are skipped automatically.')
                    ->columnSpan(2)
                    ->schema([
                        Radio::make('audience_type')
                            ->label('Send to')
                            ->options(AudienceType::class)
                            ->default(AudienceType::Lists)
                            ->required()
                            ->live(),
                        Select::make('lists')
                            ->relationship('lists', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->visible(fn (Get $get): bool => static::audienceIs($get('audience_type'), AudienceType::Lists)),
                        Select::make('selectedContacts')
                            ->label('Contacts')
                            ->relationship('selectedContacts', 'email')
                            ->multiple()
                            ->searchable(['email', 'first_name', 'last_name'])
                            ->visible(fn (Get $get): bool => static::audienceIs($get('audience_type'), AudienceType::SelectedContacts)),
                        Select::make('segment_id')
                            ->label('Segment')
                            ->relationship('segment', 'name')
                            ->preload()
                            ->searchable()
                            ->helperText('Segments are evaluated when the campaign starts sending.')
                            ->visible(fn (Get $get): bool => static::audienceIs($get('audience_type'), AudienceType::Segment)),
                    ]),
                Section::make('Sender')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('sender_identity_id')
                            ->label('From')
                            ->relationship('senderIdentity', 'email', fn ($query) => $query->usable()->with('smtpProvider'))
                            ->getOptionLabelFromRecordUsing(fn (SenderIdentity $record): string => "{$record->name} <{$record->email}>"
                                .($record->isVerified() ? '' : ' (unverified)')
                                .($record->smtpProvider->isSandbox() ? ' (sandbox: captured, not delivered)' : ''))
                            ->preload()
                            ->searchable()
                            ->helperText('Only verified sender identities on enabled providers are listed.'),
                    ]),
                Section::make('First email')
                    ->description('Everyone in the audience gets this email first. Follow-ups below go only to people who have not replied, unsubscribed or bounced.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('email_template_id')
                            ->label('Start from a template')
                            ->relationship('emailTemplate', 'name', fn (Builder $query, Get $get) => $query->usableFor(static::purpose($get('purpose'))))
                            ->preload()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $template = EmailTemplate::query()->find($state);

                                if ($template === null) {
                                    return;
                                }

                                $set('subject', $template->subject);
                                $set('body', RichContentRenderer::make($template->body)->toArray());
                                $set('text_body', $template->text_body);
                                $set('required_variables', $template->required_variables ?? []);
                            })
                            ->helperText('Only active templates for this email type are listed. Choosing one replaces the subject and content.'),
                        TextInput::make('subject')
                            ->maxLength(255)
                            ->helperText('Merge tags such as {{ first_name }} work here too.'),
                        RichEditor::make('body')
                            ->label('Email content')
                            ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                            ->fileAttachments(false)
                            ->extraInputAttributes(['style' => 'min-height: 20rem;'])
                            ->helperText('Type {{ to insert merge tags. Marketing emails get an unsubscribe footer automatically.'),
                        Textarea::make('text_body')
                            ->label('Plain-text version')
                            ->rows(5)
                            ->helperText('Optional. When empty, a plain-text version is generated from the content.'),
                        TagsInput::make('required_variables')
                            ->nestedRecursiveRules(['regex:'.TemplateVariables::KEY_PATTERN])
                            ->label('Required variables')
                            ->placeholder('Add a variable name')
                            ->helperText('Contacts without a value for one of these are skipped.'),
                        static::variantsRepeater('firstEmailVariants', 'Same subject as version A'),
                    ]),
                Section::make('Follow-ups')
                    ->description('Each follow-up waits the given number of days after the previous email, then goes out inside the sending schedule. A reply, unsubscribe, bounce or complaint ends the sequence for that prospect.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('steps')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('position')
                            ->defaultItems(0)
                            ->maxItems(10)
                            ->addActionLabel('Add follow-up')
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): string => 'Follow-up · wait '.((int) ($state['wait_days'] ?? 0)).' day(s)'.(filled($state['subject'] ?? null) ? ' · new subject' : ' · same thread'))
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('wait_days')
                                        ->label('Wait')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->maxValue(60)
                                        ->default(3)
                                        ->suffix('days')
                                        ->required()
                                        ->live(onBlur: true),
                                    Select::make('condition')
                                        ->label('Send')
                                        ->options(StepCondition::class)
                                        ->default(StepCondition::NoReply)
                                        ->required(),
                                    TextInput::make('subject')
                                        ->maxLength(255)
                                        ->placeholder('Re: first email\'s subject')
                                        ->helperText('Leave empty to reply in the same thread.')
                                        ->live(onBlur: true),
                                ]),
                                RichEditor::make('body')
                                    ->label('Content')
                                    ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                                    ->fileAttachments(false)
                                    ->required()
                                    ->extraInputAttributes(['style' => 'min-height: 10rem;']),
                                Textarea::make('text_body')
                                    ->label('Plain-text version')
                                    ->rows(3)
                                    ->helperText('Optional. Generated from the content when empty.'),
                                static::variantsRepeater('variants', 'Same as version A'),
                            ]),
                    ]),
                Section::make('Sending schedule')
                    ->description('Emails, including follow-ups, only go out on these days and hours. Outside them they wait.')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('send_days')
                            ->label('Days')
                            ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                            ->default(fn (): array => app(CurrentClient::class)->get()?->default_send_days ?? [1, 2, 3, 4, 5])
                            ->columns(7)
                            ->helperText('None ticked means every day.')
                            ->dehydrateStateUsing(fn (?array $state): ?array => filled($state) ? array_values(array_map(intval(...), $state)) : null)
                            ->columnSpanFull(),
                        TimePicker::make('send_window_start')
                            ->label('From')
                            ->seconds(false)
                            ->format('H:i')
                            ->default(fn (): ?string => static::clientDefault('default_send_window_start', '09:00'))
                            ->requiredWith('send_window_end')
                            ->helperText('Leave both times empty to send at any hour.'),
                        TimePicker::make('send_window_end')
                            ->label('Until')
                            ->seconds(false)
                            ->format('H:i')
                            ->default(fn (): ?string => static::clientDefault('default_send_window_end', '17:00'))
                            ->requiredWith('send_window_start')
                            ->after('send_window_start'),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->placeholder('The client\'s time zone')
                            ->default(fn (): ?string => app(CurrentClient::class)->get()?->timezone),
                        Toggle::make('use_prospect_timezone')
                            ->label('Use each prospect\'s own time zone')
                            ->helperText('When a prospect has a time zone, their local days and hours apply.')
                            ->inline(false),
                    ]),
                Section::make('Tracking')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('track_opens')
                            ->label('Track opens')
                            ->default(fn (): bool => app(CurrentClient::class)->get()?->default_track_opens ?? true)
                            ->helperText('Approximate: some mail apps open every email, others block the image.'),
                        Toggle::make('track_clicks')
                            ->label('Track clicks')
                            ->default(fn (): bool => app(CurrentClient::class)->get()?->default_track_clicks ?? false)
                            ->helperText('Links go through this site first. Rewritten links can hurt deliverability for cold outreach.'),
                    ]),
            ]);
    }

    /**
     * A/B versions of an email. The content above is version A, which keeps whatever share of prospects the other
     * versions leave; each prospect always gets the same version.
     */
    private static function variantsRepeater(string $relationship, string $subjectPlaceholder): Repeater
    {
        return Repeater::make($relationship)
            ->label('A/B test versions')
            ->helperText('Optional. The content above is version A and gets the share of prospects the other versions leave. Winners are only named once each version reached '.AbTestResults::MIN_SAMPLE.' people.')
            ->relationship()
            ->orderColumn('position')
            ->defaultItems(0)
            ->maxItems(CampaignVariant::MAX_VARIANTS)
            ->addActionLabel('Add version')
            ->collapsible()
            ->collapsed()
            ->itemLabel(fn (array $state): string => 'Alternative version (B, C, … in this order) · '.((int) ($state['weight'] ?? 0)).'% of prospects')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (collect($value ?? [])->sum(fn (array $item): int => (int) ($item['weight'] ?? 0)) > 99) {
                    $fail('The versions\' shares add up to more than 99%. Leave at least 1% for version A.');
                }
            })
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('weight')
                        ->label('Share')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(99)
                        ->default(50)
                        ->suffix('%')
                        ->required()
                        ->live(onBlur: true),
                    TextInput::make('subject')
                        ->maxLength(255)
                        ->placeholder($subjectPlaceholder)
                        ->columnSpan(2),
                ]),
                RichEditor::make('body')
                    ->label('Content')
                    ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                    ->fileAttachments(false)
                    ->required(),
                Textarea::make('text_body')
                    ->label('Plain-text version')
                    ->rows(3),
            ]);
    }

    /**
     * A client's default, or the fallback while the client has not set one.
     */
    private static function clientDefault(string $setting, string $fallback): string
    {
        $client = app(CurrentClient::class)->get();

        return filled($client?->{$setting}) ? (string) $client->{$setting} : $fallback;
    }

    private static function purpose(mixed $state): EmailPurpose
    {
        return $state instanceof EmailPurpose ? $state : (EmailPurpose::tryFrom((string) $state) ?? EmailPurpose::Marketing);
    }

    private static function audienceIs(mixed $state, AudienceType $type): bool
    {
        return ($state instanceof AudienceType ? $state : AudienceType::tryFrom((string) $state)) === $type;
    }
}
