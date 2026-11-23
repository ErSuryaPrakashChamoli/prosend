<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Services\AuditLogger;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add role')
                ->after(fn (Role $record, AuditLogger $auditLogger) => $auditLogger->record('role.created', $record, ['name' => $record->name, 'permissions' => $record->permissions])),
        ];
    }
}
