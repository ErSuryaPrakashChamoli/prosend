<?php

namespace App\Filament\Resources\WarmupPlans\Schemas;

use App\Models\SmtpProvider;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WarmupPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Plan')
                    ->description('A warm-up plan caps how much ProSend sends each day, so volume grows gradually. It cannot create engagement and does not guarantee inbox placement.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('smtp_provider_id')
                            ->label('Provider')
                            ->relationship('smtpProvider', 'name')
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('sending_domain_id')
                            ->label('Only for this domain')
                            ->relationship('sendingDomain', 'domain')
                            ->preload()
                            ->placeholder('All email through the provider'),
                        DatePicker::make('start_date')
                            ->required()
                            ->default(now()->toDateString()),
                    ]),
                Section::make('Volume')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('initial_daily_volume')
                            ->label('Start at (emails per day)')
                            ->numeric()->integer()->minValue(1)
                            ->default(50)
                            ->required(),
                        TextInput::make('target_daily_volume')
                            ->label('Target (emails per day)')
                            ->numeric()->integer()->minValue(1)
                            ->required()
                            ->gte('initial_daily_volume')
                            ->rule(fn (Get $get): Closure => static::withinProviderLimit($get)),
                        TextInput::make('max_daily_volume')
                            ->label('Never exceed (emails per day)')
                            ->numeric()->integer()->minValue(1)
                            ->placeholder('Same as target')
                            ->rule(fn (Get $get): Closure => static::withinProviderLimit($get)),
                        TextInput::make('growth_percent')
                            ->label('Increase each step by (%)')
                            ->numeric()->integer()->minValue(5)->maxValue(100)
                            ->default(30)
                            ->required(),
                        TextInput::make('ramp_interval_days')
                            ->label('Days between increases')
                            ->numeric()->integer()->minValue(1)->maxValue(30)
                            ->default(1)
                            ->required(),
                        TextInput::make('max_per_minute')
                            ->label('Emails per minute')
                            ->numeric()->integer()->minValue(1)
                            ->placeholder('Provider limit'),
                    ]),
                Section::make('Schedule and safety')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('business_days_only')
                            ->label('Send on weekdays only'),
                        TimePicker::make('sending_window_start')
                            ->label('Send from')
                            ->seconds(false),
                        TimePicker::make('sending_window_end')
                            ->label('Send until')
                            ->seconds(false)
                            ->after('sending_window_start'),
                        TextInput::make('max_hard_bounce_rate')
                            ->label('Pause above hard bounce rate (%)')
                            ->numeric()->minValue(0.1)->maxValue(10)->step(0.1)
                            ->default(2)
                            ->required(),
                        TextInput::make('max_complaint_rate')
                            ->label('Pause above complaint rate (%)')
                            ->numeric()->minValue(0.01)->maxValue(1)->step(0.01)
                            ->default(0.1)
                            ->required()
                            ->helperText('Gmail asks bulk senders to stay below 0.1% and never reach 0.3%.'),
                    ]),
            ]);
    }

    private static function withinProviderLimit(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $limit = SmtpProvider::query()->find($get('smtp_provider_id'))?->daily_limit;

            if ($limit !== null && filled($value) && (int) $value > $limit) {
                $fail("The provider allows {$limit} emails per day. A warm-up plan cannot go above the provider's limit.");
            }
        };
    }
}
