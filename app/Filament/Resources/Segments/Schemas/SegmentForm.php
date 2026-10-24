<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Enums\ConsentStatus;
use App\Enums\SegmentMatch;
use App\Models\ContactList;
use App\Services\Segments\SegmentQuery;
use App\Services\Sending\TemplateVariables;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SegmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Segment')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->rows(2)
                            ->maxLength(2000),
                    ]),
                Section::make('Matching')
                    ->columnSpan(1)
                    ->schema([
                        Radio::make('match')
                            ->hiddenLabel()
                            ->options(SegmentMatch::class)
                            ->default(SegmentMatch::All)
                            ->required(),
                    ]),
                Section::make('Rules')
                    ->description('Segments update automatically: a campaign uses whoever matches when it starts sending. Suppression and consent checks still apply on top.')
                    ->columnSpanFull()
                    ->schema([
                        Builder::make('rules')
                            ->hiddenLabel()
                            ->required()
                            ->minItems(1)
                            ->addActionLabel('Add rule')
                            ->blockNumbers(false)
                            ->blocks(static::blocks()),
                    ]),
            ]);
    }

    /**
     * @return list<Block>
     */
    private static function blocks(): array
    {
        $labels = SegmentQuery::RULE_TYPES;

        return [
            Block::make('consent')->label($labels['consent'])->icon('heroicon-o-shield-check')->schema([
                Select::make('status')->options(ConsentStatus::class)->required(),
            ]),
            Block::make('list')->label($labels['list'])->icon('heroicon-o-queue-list')->columns(2)->schema([
                Select::make('operator')->options(['in' => 'Is in list', 'not_in' => 'Is not in list'])->default('in')->required(),
                Select::make('contact_list_id')->label('List')->options(fn (): array => ContactList::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
            ]),
            Block::make('tag')->label($labels['tag'])->icon('heroicon-o-tag')->columns(2)->schema([
                Select::make('operator')->options(['has' => 'Has tag', 'missing' => 'Does not have tag'])->default('has')->required(),
                TextInput::make('tag')->required()->maxLength(50),
            ]),
            Block::make('email_domain')->label($labels['email_domain'])->icon('heroicon-o-at-symbol')->schema([
                TextInput::make('domain')->placeholder('example.com')->required()->maxLength(255),
            ]),
            Block::make('company')->label($labels['company'])->icon('heroicon-o-building-office')->columns(2)->schema([
                Select::make('operator')->options(['contains' => 'Contains', 'is_empty' => 'Is empty', 'is_not_empty' => 'Is not empty'])->default('contains')->required()->live(),
                TextInput::make('value')->maxLength(255)->visible(fn (Get $get): bool => $get('operator') === 'contains')->required(fn (Get $get): bool => $get('operator') === 'contains'),
            ]),
            Block::make('custom_attribute')->label($labels['custom_attribute'])->icon('heroicon-o-variable')->columns(3)->schema([
                TextInput::make('key')->label('Attribute')->required()->regex(TemplateVariables::KEY_PATTERN)->validationMessages(['regex' => 'Use lowercase letters, numbers, and underscores.']),
                Select::make('operator')->options(['equals' => 'Equals', 'exists' => 'Has a value', 'missing' => 'Has no value'])->default('equals')->required()->live(),
                TextInput::make('value')->maxLength(255)->visible(fn (Get $get): bool => $get('operator') === 'equals'),
            ]),
            Block::make('added')->label($labels['added'])->icon('heroicon-o-calendar')->columns(2)->schema([
                Select::make('operator')->options(['within_days' => 'In the last … days', 'after' => 'On or after', 'before' => 'Before'])->default('within_days')->required()->live(),
                TextInput::make('days')->numeric()->integer()->minValue(1)->default(30)->visible(fn (Get $get): bool => $get('operator') === 'within_days')->required(fn (Get $get): bool => $get('operator') === 'within_days'),
                DatePicker::make('date')->visible(fn (Get $get): bool => $get('operator') !== 'within_days')->required(fn (Get $get): bool => $get('operator') !== 'within_days'),
            ]),
            Block::make('unsubscribed')->label($labels['unsubscribed'])->icon('heroicon-o-bell-slash')->schema([
                Select::make('value')->options(['no' => 'Has not unsubscribed', 'yes' => 'Has unsubscribed'])->default('no')->required(),
            ]),
            Block::make('engagement')->label($labels['engagement'])->icon('heroicon-o-cursor-arrow-rays')->columns(2)->schema([
                Select::make('event')->options(['opened' => 'Opened or clicked', 'clicked' => 'Clicked'])->default('opened')->required()->helperText('Approximate: privacy proxies and security scanners can trigger opens and clicks.'),
                TextInput::make('days')->label('Within the last … days')->numeric()->integer()->minValue(1)->default(30)->required(),
            ]),
            Block::make('received')->label($labels['received'])->icon('heroicon-o-envelope')->columns(2)->schema([
                Select::make('operator')->options(['received' => 'Was emailed', 'not_received' => 'Was not emailed'])->default('not_received')->required(),
                TextInput::make('days')->label('In the last … days')->numeric()->integer()->minValue(1)->default(7)->required(),
            ]),
        ];
    }
}
