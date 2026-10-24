<?php

namespace App\Filament\Resources\SendingDomains\Pages;

use App\Filament\Resources\SendingDomains\SendingDomainResource;
use App\Services\Deliverability\DomainAuthenticator;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSendingDomain extends EditRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->dns_checked_at === null) {
            app(DomainAuthenticator::class)->check($this->record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
