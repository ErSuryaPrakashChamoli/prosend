<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create API key')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => ApiKeyResource::canCreate())
                ->modalHeading('Create API key')
                ->schema(fn (Schema $schema): Schema => ApiKeyResource::form($schema))
                ->action(function (array $data, AuditLogger $auditLogger): void {
                    [$apiKey, $plaintext] = ApiKey::issue($data, auth()->user());

                    $auditLogger->record('api_key.created', $apiKey, ApiKeyResource::auditDetails($apiKey));

                    $this->replaceMountedAction('showNewKey', ['key' => $plaintext]);
                }),
        ];
    }

    /**
     * Shows a newly created key once. It is never stored in plain text, so it cannot be shown again.
     */
    public function showNewKeyAction(): Action
    {
        return Action::make('showNewKey')
            ->modalHeading('Copy your API key now')
            ->modalIcon(Heroicon::OutlinedKey)
            ->schema(fn (array $arguments): array => [
                Callout::make('This is the only time the full key is shown.')
                    ->description('Store it in your application\'s secret store. If you lose it, rotate the key.')
                    ->warning(),
                TextInput::make('key')
                    ->label('API key')
                    ->default($arguments['key'] ?? '')
                    ->readOnly()
                    ->copyable()
                    ->extraInputAttributes(['class' => 'font-mono']),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }
}
