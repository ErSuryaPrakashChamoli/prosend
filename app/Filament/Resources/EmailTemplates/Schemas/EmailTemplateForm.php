<?php

namespace App\Filament\Resources\EmailTemplates\Schemas;

use App\Enums\EmailPurpose;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\TemplateVariables;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Template')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->options(TemplateCategory::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $category = $state instanceof TemplateCategory ? $state : TemplateCategory::tryFrom((string) $state);

                                if ($category !== null) {
                                    $set('purpose', $category->purpose()->value);
                                }
                            }),
                        Radio::make('purpose')
                            ->label('Email type')
                            ->options(EmailPurpose::class)
                            ->default(EmailPurpose::Transactional)
                            ->required()
                            ->helperText('Transactional templates can only be used for transactional email, and marketing templates only for marketing, so one is never sent as the other.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Status')
                    ->columnSpan(1)
                    ->schema([
                        Radio::make('status')
                            ->hiddenLabel()
                            ->options(TemplateStatus::class)
                            ->default(TemplateStatus::Draft)
                            ->required(),
                    ]),
                Section::make('Content')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('subject')
                            ->required()
                            ->maxLength(255)
                            ->rule(fn (): Closure => static::variablesRule()),
                        RichEditor::make('body')
                            ->label('HTML content')
                            ->required()
                            ->mergeTags(EmailContentRenderer::MERGE_TAGS)
                            ->fileAttachments(false)
                            ->extraInputAttributes(['style' => 'min-height: 18rem;'])
                            ->helperText('Type {{ to insert a merge tag. Other variables, such as {{ order_number }}, are filled from the contact\'s custom attributes.'),
                        Textarea::make('text_body')
                            ->label('Plain-text version')
                            ->rows(6)
                            ->rule(fn (): Closure => static::variablesRule())
                            ->helperText('Optional. When empty, a plain-text version is generated from the HTML.'),
                        TagsInput::make('required_variables')
                            ->nestedRecursiveRules(['regex:'.TemplateVariables::KEY_PATTERN])
                            ->label('Required variables')
                            ->placeholder('Add a variable name')
                            ->suggestions(fn (Get $get): array => app(TemplateVariables::class)->extract($get('subject'), $get('body'), $get('text_body')))
                            ->helperText('Recipients without a value for one of these are skipped instead of receiving an incomplete email.')
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $used = app(TemplateVariables::class)->extract($get('subject'), $get('body'), $get('text_body'));
                                $unused = array_diff((array) $value, $used);

                                if ($unused !== []) {
                                    $fail('These required variables are not used in the template: '.implode(', ', $unused).'.');
                                }
                            }),
                    ]),
            ]);
    }

    /**
     * Rejects placeholders that can never be filled, such as {{ First Name }}.
     */
    private static function variablesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', (string) $value, $matches);

            $invalid = app(TemplateVariables::class)->invalid($matches[1]);

            if ($invalid !== []) {
                $fail('Variable names may only use lowercase letters, numbers, and underscores: '.implode(', ', $invalid).'.');
            }
        };
    }
}
