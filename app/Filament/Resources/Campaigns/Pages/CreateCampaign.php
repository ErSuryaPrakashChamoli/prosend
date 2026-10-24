<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'New campaign';

    protected function afterCreate(): void
    {
        $this->record->forceFill(['created_by' => auth()->id()])->save();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
