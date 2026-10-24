<?php

namespace App\Filament\Resources\SmtpProviders\Pages;

use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSmtpProvider extends EditRecord
{
    protected static string $resource = SmtpProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalDescription('Providers with sender identities or sending history cannot be deleted; disable them instead.'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
