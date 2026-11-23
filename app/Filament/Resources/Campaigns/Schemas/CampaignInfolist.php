<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Services\Campaigns\AbTestResults;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Overview')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('purpose')
                            ->badge(),
                        TextEntry::make('audience')
                            ->state(fn (Campaign $record): string => match ($record->audience_type) {
                                AudienceType::AllContacts => 'All contacts',
                                AudienceType::Lists => 'Lists: '.($record->lists->pluck('name')->join(', ') ?: 'none chosen'),
                                AudienceType::SelectedContacts => $record->selectedContacts()->count().' selected contacts',
                                AudienceType::Segment => 'Segment: '.($record->segment?->name ?? 'none chosen'),
                            }),
                        TextEntry::make('emailTemplate.name')
                            ->label('Template')
                            ->placeholder('No template'),
                        TextEntry::make('required_variables')
                            ->label('Required variables')
                            ->badge()
                            ->color('warning')
                            ->placeholder('None'),
                        TextEntry::make('senderIdentity.email')
                            ->label('From')
                            ->formatStateUsing(fn (Campaign $record): string => "{$record->senderIdentity->name} <{$record->senderIdentity->email}>")
                            ->placeholder('Not chosen'),
                        TextEntry::make('subject')
                            ->placeholder('No subject yet')
                            ->columnSpanFull(),
                        TextEntry::make('review_note')
                            ->label('Review note')
                            ->color('warning')
                            ->visible(fn (Campaign $record): bool => filled($record->review_note))
                            ->columnSpanFull(),
                    ]),
                Section::make('Sequence')
                    ->description('Follow-ups go only to prospects who have not replied, unsubscribed or bounced.')
                    ->columnSpan(2)
                    ->visible(fn (Campaign $record): bool => $record->steps()->exists())
                    ->schema([
                        RepeatableEntry::make('sequence')
                            ->hiddenLabel()
                            ->state(fn (Campaign $record): array => static::sequenceRows($record))
                            ->table([
                                TableColumn::make('Step'),
                                TableColumn::make('When'),
                                TableColumn::make('Sent if'),
                                TableColumn::make('Subject'),
                                TableColumn::make('Content'),
                                TableColumn::make('Sent'),
                            ])
                            ->schema([
                                TextEntry::make('step'),
                                TextEntry::make('when'),
                                TextEntry::make('condition'),
                                TextEntry::make('subject'),
                                TextEntry::make('content')->color('gray'),
                                TextEntry::make('sent')->numeric(),
                            ]),
                    ]),
                Section::make('A/B test versions')
                    ->description('What each version says. The campaign content above is version A of the first email.')
                    ->columnSpan(2)
                    ->collapsible()
                    ->visible(fn (Campaign $record): bool => $record->variants()->exists())
                    ->schema([
                        RepeatableEntry::make('version_content')
                            ->hiddenLabel()
                            ->state(fn (Campaign $record): array => $record->variants()->with('step')->orderByRaw('coalesce(campaign_step_id, 0)')->orderBy('position')->get()
                                ->map(fn (CampaignVariant $variant): array => [
                                    'version' => 'Version '.$variant->label().' of '.($variant->step === null ? 'the first email' : 'step '.$variant->step->stepNumber()).' · '.$variant->weight.'%'.($variant->is_active ? '' : ' (stopped)'),
                                    'subject' => filled($variant->subject) ? $variant->subject : 'Same subject as version A',
                                    'content' => Str::limit(trim(html_entity_decode(strip_tags((string) $variant->body), ENT_QUOTES | ENT_HTML5)), 600),
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('version')->hiddenLabel()->weight('bold'),
                                TextEntry::make('subject')->hiddenLabel(),
                                TextEntry::make('content')->hiddenLabel()->color('gray')->extraAttributes(['style' => 'white-space: pre-wrap;']),
                            ]),
                    ]),
                Section::make('A/B test results')
                    ->description('Replies decide; opens only until the first reply. A winner needs '.AbTestResults::MIN_SAMPLE.' sent emails per version and 95% confidence.')
                    ->columnSpan(2)
                    ->visible(fn (Campaign $record): bool => $record->variants()->exists())
                    ->schema([
                        RepeatableEntry::make('ab_tests')
                            ->hiddenLabel()
                            ->state(fn (Campaign $record): array => array_map(fn (array $test): array => [
                                'email' => $test['step'] === 1 ? 'First email' : "Follow-up, step {$test['step']}",
                                'verdict' => $test['verdict'],
                                'versions' => array_map(fn (array $version): array => [...$version, 'name' => $version['label'].($version['active'] ? '' : ' (stopped)')], $test['versions']),
                            ], app(AbTestResults::class)->for($record)))
                            ->schema([
                                TextEntry::make('email')
                                    ->hiddenLabel()
                                    ->weight('bold'),
                                TextEntry::make('verdict')
                                    ->hiddenLabel()
                                    ->color('gray'),
                                RepeatableEntry::make('versions')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Version'),
                                        TableColumn::make('Share'),
                                        TableColumn::make('Sent'),
                                        TableColumn::make('Opened'),
                                        TableColumn::make('Clicked'),
                                        TableColumn::make('Replied'),
                                    ])
                                    ->schema([
                                        TextEntry::make('name'),
                                        TextEntry::make('weight')->suffix('%'),
                                        TextEntry::make('sent')->numeric(),
                                        TextEntry::make('open_rate')->suffix('%'),
                                        TextEntry::make('click_rate')->suffix('%'),
                                        TextEntry::make('reply_rate')->suffix('%'),
                                    ]),
                            ]),
                    ]),
                Section::make('Schedule')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('send_rate_per_minute')
                            ->label('Rate')
                            ->suffix(' emails per minute'),
                        TextEntry::make('sending_window')
                            ->label('Sending window')
                            ->state(fn (Campaign $record): string => $record->sendingWindowFor()->describe().($record->use_prospect_timezone ? ', or each prospect\'s own time zone' : '')),
                        TextEntry::make('tracking')
                            ->state(fn (Campaign $record): string => match (true) {
                                $record->track_opens && $record->track_clicks => 'Opens and clicks',
                                $record->track_opens => 'Opens',
                                $record->track_clicks => 'Clicks',
                                default => 'Off',
                            }),
                        TextEntry::make('scheduled_at')
                            ->label('Scheduled for')
                            ->dateTime()
                            ->placeholder('Sends when launched'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime()
                            ->placeholder('Not started'),
                        TextEntry::make('completed_at')
                            ->label('Finished')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label('Created by')
                            ->placeholder('—'),
                        TextEntry::make('submitter.name')
                            ->label('Submitted by')
                            ->helperText(fn (Campaign $record): ?string => $record->submitted_at?->toDayDateTimeString())
                            ->visible(fn (Campaign $record): bool => $record->submitted_at !== null),
                        TextEntry::make('approver.name')
                            ->label('Approved by')
                            ->helperText(fn (Campaign $record): ?string => $record->approved_at
                                ? $record->approved_at->toDayDateTimeString().' · '.Number::format((int) $record->approved_recipient_count).' recipients approved'
                                : null)
                            ->placeholder(fn (Campaign $record): string => $record->status === CampaignStatus::PendingApproval ? 'Waiting for approval' : 'Launched before approval was required')
                            ->visible(fn (Campaign $record): bool => $record->status !== CampaignStatus::Draft),
                    ]),
            ]);
    }

    /**
     * One row per email in the sequence, with the day it goes out relative to the first email.
     *
     * @return list<array{step: string, when: string, condition: string, subject: string, content: string, sent: int}>
     */
    public static function sequenceRows(Campaign $record): array
    {
        $sent = $record->messages()
            ->whereIn('status', [...MessageStatus::acceptedByProvider(), MessageStatus::Captured])
            ->toBase()
            ->selectRaw('step_number, count(*) as aggregate')
            ->groupBy('step_number')
            ->pluck('aggregate', 'step_number');

        $rows = [[
            'step' => '1',
            'when' => 'Day 1',
            'condition' => 'Everyone eligible',
            'subject' => (string) $record->subject,
            'content' => Str::limit(trim(html_entity_decode(strip_tags((string) $record->body), ENT_QUOTES | ENT_HTML5)), 160),
            'sent' => (int) ($sent[1] ?? 0),
        ]];
        $day = 1;

        foreach ($record->steps as $step) {
            $day += $step->wait_days;
            $rows[] = [
                'step' => (string) $step->stepNumber(),
                'when' => "Day {$day} or later",
                'condition' => $step->condition->getLabel(),
                'subject' => $step->repliesInThread() ? 'Reply in the same thread' : (string) $step->subject,
                'content' => Str::limit(trim(html_entity_decode(strip_tags((string) $step->body), ENT_QUOTES | ENT_HTML5)), 160),
                'sent' => (int) ($sent[$step->stepNumber()] ?? 0),
            ];
        }

        return $rows;
    }
}
