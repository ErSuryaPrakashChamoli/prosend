<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AutomationActionType: string implements HasLabel
{
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';
    case SetStage = 'set_stage';
    case AssignOwner = 'assign_owner';
    case CreateTask = 'create_task';
    case Notify = 'notify';
    case StopSequences = 'stop_sequences';
    case SendEmail = 'send_email';
    case SendWebhook = 'send_webhook';

    public function getLabel(): string
    {
        return match ($this) {
            self::AddTag => 'Add a tag',
            self::RemoveTag => 'Remove a tag',
            self::SetStage => 'Change the stage',
            self::AssignOwner => 'Assign an owner',
            self::CreateTask => 'Create a task',
            self::Notify => 'Notify someone',
            self::StopSequences => 'Stop the prospect\'s sequences',
            self::SendEmail => 'Send an email from a template',
            self::SendWebhook => 'Call a webhook',
        };
    }

    /**
     * Actions that change or email a prospect, so they cannot run for campaign events.
     */
    public function needsProspect(): bool
    {
        return ! in_array($this, [self::Notify, self::SendWebhook, self::CreateTask], true);
    }
}
