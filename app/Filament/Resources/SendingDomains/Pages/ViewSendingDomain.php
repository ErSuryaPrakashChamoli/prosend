<?php

namespace App\Filament\Resources\SendingDomains\Pages;

use App\Filament\Resources\SendingDomains\SendingDomainResource;
use App\Models\SendingDomain;
use App\Services\Deliverability\DomainAuthenticator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property SendingDomain $record
 */
class ViewSendingDomain extends ViewRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (DomainAuthenticator $authenticator): void {
                    $problem = $authenticator->check($this->record);

                    Notification::make()
                        ->title($this->record->refresh()->isReady() ? "{$this->record->domain} is ready" : 'Check complete')
                        ->body($problem ?? implode(' ', $this->record->readinessIssues()))
                        ->status($this->record->isReady() ? 'success' : 'warning')
                        ->send();

                    $this->redirect(SendingDomainResource::getUrl('view', ['record' => $this->record]));
                }),
            EditAction::make(),
        ];
    }
}
