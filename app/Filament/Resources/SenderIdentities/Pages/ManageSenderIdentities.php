<?php

namespace App\Filament\Resources\SenderIdentities\Pages;

use App\Filament\Resources\SenderIdentities\SenderIdentityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSenderIdentities extends ManageRecords
{
    protected static string $resource = SenderIdentityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
