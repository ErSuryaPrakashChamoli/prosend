<?php

namespace App\Filament\Resources\SmtpProviders\Pages;

use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSmtpProvider extends CreateRecord
{
    protected static string $resource = SmtpProviderResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
