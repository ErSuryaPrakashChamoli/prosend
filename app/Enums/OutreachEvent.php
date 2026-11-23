<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Things that happen in outreach. Each can start automation rules and is sent to subscribed webhook endpoints.
 */
enum OutreachEvent: string implements HasLabel
{
    case ProspectCreated = 'prospect.created';
    case ProspectImported = 'prospect.imported';
    case ProspectStageChanged = 'prospect.stage_changed';
    case ProspectUnsubscribed = 'prospect.unsubscribed';
    case CampaignStarted = 'campaign.started';
    case CampaignCompleted = 'campaign.completed';
    case EmailSent = 'email.sent';
    case EmailOpened = 'email.opened';
    case EmailClicked = 'email.clicked';
    case EmailReplied = 'email.replied';
    case EmailBounced = 'email.bounced';

    public function getLabel(): string
    {
        return match ($this) {
            self::ProspectCreated => 'Prospect created',
            self::ProspectImported => 'Prospect imported',
            self::ProspectStageChanged => 'Prospect stage changed',
            self::ProspectUnsubscribed => 'Prospect unsubscribed',
            self::CampaignStarted => 'Campaign started',
            self::CampaignCompleted => 'Campaign finished',
            self::EmailSent => 'Email sent',
            self::EmailOpened => 'Email opened',
            self::EmailClicked => 'Link clicked',
            self::EmailReplied => 'Email replied to',
            self::EmailBounced => 'Email bounced',
        };
    }

    /**
     * Whether the event is about a prospect. Campaign events are not, so prospect actions cannot run for them.
     */
    public function concernsProspect(): bool
    {
        return ! in_array($this, [self::CampaignStarted, self::CampaignCompleted], true);
    }
}
