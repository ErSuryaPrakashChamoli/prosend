<?php

namespace App\Filament\Resources\InboundMailboxes\Pages;

use App\Filament\Resources\InboundMailboxes\InboundMailboxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInboundMailboxes extends ManageRecords
{
    protected static string $resource = InboundMailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add mailbox'),
        ];
    }
}
