<?php

namespace App\Filament\Resources\WarmupPlans;

use App\Filament\Resources\WarmupPlans\Pages\CreateWarmupPlan;
use App\Filament\Resources\WarmupPlans\Pages\EditWarmupPlan;
use App\Filament\Resources\WarmupPlans\Pages\ListWarmupPlans;
use App\Filament\Resources\WarmupPlans\Pages\ViewWarmupPlan;
use App\Filament\Resources\WarmupPlans\Schemas\WarmupPlanForm;
use App\Filament\Resources\WarmupPlans\Schemas\WarmupPlanInfolist;
use App\Filament\Resources\WarmupPlans\Tables\WarmupPlansTable;
use App\Models\WarmupPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WarmupPlanResource extends Resource
{
    protected static ?string $model = WarmupPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Deliverability';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Warm-up planner';

    protected static ?string $modelLabel = 'warm-up plan';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WarmupPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WarmupPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarmupPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarmupPlans::route('/'),
            'create' => CreateWarmupPlan::route('/create'),
            'view' => ViewWarmupPlan::route('/{record}'),
            'edit' => EditWarmupPlan::route('/{record}/edit'),
        ];
    }
}
