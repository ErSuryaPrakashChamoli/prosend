<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add webhook')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => WebhookEndpointResource::canCreate())
                ->schema(fn (Schema $schema): Schema => WebhookEndpointResource::form($schema))
                ->action(function (array $data, AuditLogger $auditLogger): void {
                    $endpoint = new WebhookEndpoint($data);
                    $endpoint->forceFill(['created_by' => auth()->id(), 'secret' => 'pending'])->save();
                    $secret = $endpoint->regenerateSecret();
                    $auditLogger->record('webhook.created', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events]);

                    $this->replaceMountedAction('showSecret', ['secret' => $secret]);
                }),
        ];
    }

    /**
     * Shows a new signing secret once.
     */
    public function showSecretAction(): Action
    {
        return static::secretAction();
    }

    public static function secretAction(): Action
    {
        return Action::make('showSecret')
            ->modalHeading('Copy the signing secret now')
            ->modalIcon(Heroicon::OutlinedKey)
            ->schema(fn (array $arguments): array => [
                Callout::make('This is the only time the secret is shown.')
                    ->description('Use it to check the webhook-signature header of every request (Standard Webhooks). If you lose it, rotate it.')
                    ->warning(),
                TextInput::make('secret')
                    ->label('Signing secret')
                    ->default($arguments['secret'] ?? '')
                    ->readOnly()
                    ->copyable()
                    ->extraInputAttributes(['class' => 'font-mono']),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }
}
