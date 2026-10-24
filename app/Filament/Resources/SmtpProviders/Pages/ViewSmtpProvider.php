<?php

namespace App\Filament\Resources\SmtpProviders\Pages;

use App\Filament\Resources\SmtpProviders\SmtpProviderActions;
use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSmtpProvider extends ViewRecord
{
    protected static string $resource = SmtpProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SmtpProviderActions::testConnection(),
            SmtpProviderActions::sendTestEmail(),
            EditAction::make(),
            ActionGroup::make([
                SmtpProviderActions::generateWebhookUrl(),
                SmtpProviderActions::toggleEnabled(),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }
}
