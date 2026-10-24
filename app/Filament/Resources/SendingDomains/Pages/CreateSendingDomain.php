<?php

namespace App\Filament\Resources\SendingDomains\Pages;

use App\Filament\Resources\SendingDomains\SendingDomainResource;
use App\Services\Deliverability\DomainAuthenticator;
use Filament\Resources\Pages\CreateRecord;

class CreateSendingDomain extends CreateRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function afterCreate(): void
    {
        app(DomainAuthenticator::class)->check($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
