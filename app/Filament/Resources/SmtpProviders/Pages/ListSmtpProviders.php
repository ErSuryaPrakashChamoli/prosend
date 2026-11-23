<?php

namespace App\Filament\Resources\SmtpProviders\Pages;

use App\Enums\ProviderType;
use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use App\Services\Providers\OAuth\OAuthClient;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSmtpProviders extends ListRecords
{
    protected static string $resource = SmtpProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->connectAction('google', ProviderType::Gmail, 'Connect Gmail'),
            $this->connectAction('microsoft', ProviderType::Microsoft365, 'Connect Microsoft 365'),
            CreateAction::make()
                ->label('Add SMTP provider'),
        ];
    }

    /**
     * Shown once the OAuth app's credentials are configured (GOOGLE_* or MICROSOFT_* in .env).
     */
    private function connectAction(string $slug, ProviderType $type, string $label): Action
    {
        return Action::make("connect_{$slug}")
            ->label($label)
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => SmtpProviderResource::canCreate() && app(OAuthClient::class)->isConfigured($type))
            ->url(fn (): string => route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => $slug]));
    }
}
