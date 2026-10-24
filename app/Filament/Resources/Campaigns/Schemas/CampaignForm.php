<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\AudienceType;
use App\Enums\EmailPurpose;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\TemplateVariables;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                            ->helperText('Leave empty to start sending as soon as you launch.'),
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
                            ->relationship('senderIdentity', 'email', fn ($query) => $query->usable())
                            ->getOptionLabelFromRecordUsing(fn (SenderIdentity $record): string => "{$record->name} <{$record->email}>".($record->isVerified() ? '' : ' (unverified)'))
                            ->preload()
                            ->searchable()
                            ->helperText('Only verified sender identities on enabled providers are listed.'),
                    ]),
                Section::make('Content')
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
                    ]),
            ]);
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
