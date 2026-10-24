<?php

namespace App\Filament\Resources\SmtpProviders\Pages;

use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSmtpProviders extends ListRecords
{
    protected static string $resource = SmtpProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
