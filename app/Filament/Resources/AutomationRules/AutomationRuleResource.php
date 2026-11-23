<?php

namespace App\Filament\Resources\AutomationRules;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionType;
use App\Enums\ContactStage;
use App\Enums\OutreachEvent;
use App\Enums\Permission;
use App\Enums\TemplateStatus;
use App\Filament\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Resources\AutomationRules\RelationManagers\RunsRelationManager;
use App\Models\AutomationRule;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Models\WebhookEndpoint;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Rules that act on their own when something happens: tag, assign, notify, create a task, stop sequences, send an email
 * or call a webhook.
 */
class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static ?string $modelLabel = 'automation rule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Email Operations';

    protected static ?string $navigationLabel = 'Automation rules';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('When')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('trigger')
                            ->options(OutreachEvent::class)
                            ->required()
                            ->helperText('Campaign events are not about one prospect, so only notify, task and webhook actions run for them.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Section::make('Only if')
                    ->description('Every condition must hold. Leave empty to always run.')
                    ->schema([
                        Builder::make('conditions')
                            ->hiddenLabel()
                            ->addActionLabel('Add condition')
                            ->blocks([
                                Block::make(AutomationConditionType::StageIs->value)->label(AutomationConditionType::StageIs->getLabel())
                                    ->schema([Select::make('value')->label('Stage')->options(ContactStage::class)->required()]),
                                Block::make(AutomationConditionType::HasTag->value)->label(AutomationConditionType::HasTag->getLabel())
                                    ->schema([TextInput::make('value')->label('Tag')->required()->maxLength(50)]),
                                Block::make(AutomationConditionType::IndustryIs->value)->label(AutomationConditionType::IndustryIs->getLabel())
                                    ->schema([TextInput::make('value')->label('Industry')->required()->maxLength(255)]),
                                Block::make(AutomationConditionType::CampaignIs->value)->label(AutomationConditionType::CampaignIs->getLabel())
                                    ->schema([Select::make('value')->label('Campaign')->options(fn (): array => Campaign::query()->latest('id')->pluck('name', 'id')->all())->searchable()->required()]),
                                Block::make(AutomationConditionType::ConsentGranted->value)->label(AutomationConditionType::ConsentGranted->getLabel())
                                    ->schema([]),
                                Block::make(AutomationConditionType::HasOwner->value)->label(AutomationConditionType::HasOwner->getLabel())
                                    ->schema([]),
                            ]),
                    ]),
                Section::make('Then')
                    ->description('Actions run in order. Emails go through the usual consent, suppression and sending checks.')
                    ->schema([
                        Builder::make('actions')
                            ->hiddenLabel()
                            ->required()
                            ->minItems(1)
                            // A rule that emails prospects works like an approved campaign, so only people who may
                            // approve campaigns (and compose email) can set one up.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $sendsEmail = collect($value ?? [])->contains(fn (array $action): bool => ($action['type'] ?? null) === AutomationActionType::SendEmail->value);

                                if ($sendsEmail && ! (auth()->user()->hasPermission(Permission::ApproveCampaigns) && auth()->user()->hasPermission(Permission::ComposeEmail))) {
                                    $fail('Only people who may approve campaigns can add "Send an email" to a rule.');
                                }
                            })
                            ->addActionLabel('Add action')
                            ->blocks([
                                Block::make(AutomationActionType::AddTag->value)->label(AutomationActionType::AddTag->getLabel())
                                    ->schema([TextInput::make('tag')->required()->maxLength(50)]),
                                Block::make(AutomationActionType::RemoveTag->value)->label(AutomationActionType::RemoveTag->getLabel())
                                    ->schema([TextInput::make('tag')->required()->maxLength(50)]),
                                Block::make(AutomationActionType::SetStage->value)->label(AutomationActionType::SetStage->getLabel())
                                    ->schema([Select::make('stage')->options(ContactStage::class)->required()]),
                                Block::make(AutomationActionType::AssignOwner->value)->label(AutomationActionType::AssignOwner->getLabel())
                                    ->schema([Select::make('user_id')->label('Owner')->options(fn (): array => static::members())->required()]),
                                Block::make(AutomationActionType::CreateTask->value)->label(AutomationActionType::CreateTask->getLabel())
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('title')->required()->maxLength(200)->placeholder('Call {{ first_name }} at {{ company_name }}')->columnSpan(2),
                                        TextInput::make('due_in_days')->label('Due in')->numeric()->integer()->minValue(0)->maxValue(365)->default(1)->suffix('days'),
                                        Select::make('assign_to')->label('For')->options(fn (): array => ['owner' => 'The prospect\'s owner', ...static::members()])->default('owner')->required(),
                                    ]),
                                Block::make(AutomationActionType::Notify->value)->label(AutomationActionType::Notify->getLabel())
                                    ->schema([
                                        Select::make('user_id')->label('Who')->options(fn (): array => ['owner' => 'The prospect\'s owner', ...static::members()])->default('owner')->required(),
                                        TextInput::make('message')->required()->maxLength(200)->placeholder('{{ email }} replied'),
                                    ]),
                                Block::make(AutomationActionType::StopSequences->value)->label(AutomationActionType::StopSequences->getLabel())
                                    ->schema([]),
                                Block::make(AutomationActionType::SendEmail->value)->label(AutomationActionType::SendEmail->getLabel())
                                    ->schema([
                                        Select::make('email_template_id')->label('Template')->options(fn (): array => EmailTemplate::query()->where('status', TemplateStatus::Active)->orderBy('name')->pluck('name', 'id')->all())->required(),
                                        Select::make('sender_identity_id')->label('From')->options(fn (): array => SenderIdentity::query()->usable()->orderBy('email')->pluck('email', 'id')->all())->required(),
                                    ]),
                                Block::make(AutomationActionType::SendWebhook->value)->label(AutomationActionType::SendWebhook->getLabel())
                                    ->schema([Select::make('webhook_endpoint_id')->label('Endpoint')->options(fn (): array => WebhookEndpoint::query()->where('is_enabled', true)->get()
                                        // The description or host only: full URLs often carry a token.
                                        ->mapWithKeys(fn (WebhookEndpoint $endpoint): array => [$endpoint->getKey() => $endpoint->description ?: (string) parse_url($endpoint->url, PHP_URL_HOST)])
                                        ->all())->required()]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function members(): array
    {
        return User::query()->memberOf()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('trigger')
                    ->badge(),
                TextColumn::make('actions')
                    ->label('Does')
                    ->state(fn (AutomationRule $record): string => collect($record->actions ?? [])
                        ->map(fn (array $action): string => AutomationActionType::tryFrom((string) ($action['type'] ?? ''))?->getLabel() ?? '')
                        ->filter()
                        ->implode(', '))
                    ->wrap(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (AutomationRule $record): bool => ! static::canEdit($record)),
                TextColumn::make('run_count')
                    ->label('Runs')
                    ->numeric(),
                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No automation rules')
            ->emptyStateDescription('For example: when a prospect replies, tag them, give their owner a task, and tell your CRM through a webhook.')
            ->emptyStateIcon(Heroicon::OutlinedBolt);
    }

    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRules::route('/'),
            'create' => CreateAutomationRule::route('/create'),
            'edit' => EditAutomationRule::route('/{record}/edit'),
        ];
    }
}
