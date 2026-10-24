<?php

namespace App\Filament\Resources\WarmupPlans\Pages;

use App\Filament\Resources\WarmupPlans\WarmupPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarmupPlans extends ListRecords
{
    protected static string $resource = WarmupPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
