<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateAutomationRule extends CreateRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function afterCreate(): void
    {
        /** @var AutomationRule $rule */
        $rule = $this->record;
        $rule->forceFill(['created_by' => auth()->id()])->save();

        app(AuditLogger::class)->record('automation.created', $rule, ['name' => $rule->name, 'trigger' => $rule->trigger->value]);
    }
}
