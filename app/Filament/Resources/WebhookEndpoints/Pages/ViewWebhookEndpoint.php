<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Enums\OutreachEvent;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use App\Services\Automation\OutreachEvents;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewWebhookEndpoint extends ViewRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        $canEdit = fn (): bool => WebhookEndpointResource::canEdit($this->record);

        return [
            Action::make('test')
                ->label('Send a test event')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible($canEdit)
                ->action(function (OutreachEvents $events): void {
                    /** @var WebhookEndpoint $endpoint */
                    $endpoint = $this->record;
                    $events->queueDelivery($endpoint, OutreachEvent::ProspectCreated, [
                        ...OutreachEvents::payload(OutreachEvent::ProspectCreated, null, ['test' => true]),
                        'data' => ['test' => true, 'prospect' => ['email' => 'jane@example.com', 'first_name' => 'Jane']],
                    ]);

                    Notification::make()->title('Test event queued')->body('It appears in the delivery log below once sent.')->success()->send();
                }),
            Action::make('rotateSecret')
                ->label('Rotate signing secret')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->visible($canEdit)
                ->requiresConfirmation()
                ->modalDescription('Deliveries are signed with the new secret straight away; update the receiving application first.')
                ->action(function (AuditLogger $auditLogger): void {
                    /** @var WebhookEndpoint $endpoint */
                    $endpoint = $this->record;
                    $secret = $endpoint->regenerateSecret();
                    $auditLogger->record('webhook.secret_rotated', $endpoint, ['url' => $endpoint->url]);

                    $this->replaceMountedAction('showSecret', ['secret' => $secret]);
                }),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    public function showSecretAction(): Action
    {
        return ListWebhookEndpoints::secretAction();
    }
}
