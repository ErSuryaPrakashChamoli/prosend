<?php

namespace App\Filament\Resources\WarmupPlans\Pages;

use App\Filament\Resources\WarmupPlans\WarmupPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarmupPlan extends CreateRecord
{
    protected static string $resource = WarmupPlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
