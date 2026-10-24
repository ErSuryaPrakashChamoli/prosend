<?php

namespace App\Filament\Resources\WarmupPlans\Pages;

use App\Filament\Resources\WarmupPlans\WarmupPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWarmupPlan extends EditRecord
{
    protected static string $resource = WarmupPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
