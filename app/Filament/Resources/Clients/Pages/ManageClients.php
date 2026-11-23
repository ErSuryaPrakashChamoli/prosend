<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Services\AuditLogger;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClients extends ManageRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add client')
                ->mutateDataUsing(fn (array $data): array => [...$data, 'slug' => filled($data['slug'] ?? null) ? $data['slug'] : null])
                ->after(function (Client $record, AuditLogger $auditLogger): void {
                    $record->forceFill(['created_by' => auth()->id()])->save();
                    $auditLogger->record('client.created', $record, ['name' => $record->name]);
                }),
        ];
    }
}
