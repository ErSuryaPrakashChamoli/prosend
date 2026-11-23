<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutomationRule extends EditRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Whoever saves a rule answers for what it does; its email actions run with their permissions.
        $this->record->forceFill(['created_by' => auth()->id()])->saveQuietly();

        app(AuditLogger::class)->record('automation.updated', $this->record, ['name' => $this->record->name]);
    }
}
