<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ApiScope: string implements HasDescription, HasLabel
{
    case EmailsSend = 'emails:send';
    case EmailsSendMarketing = 'emails:send_marketing';
    case EmailsRead = 'emails:read';
    case EmailsReadAll = 'emails:read_all';
    case ProspectsRead = 'prospects:read';
    case ProspectsWrite = 'prospects:write';
    case CampaignsRead = 'campaigns:read';
    case CampaignsWrite = 'campaigns:write';
    case ConversationsRead = 'conversations:read';

    /**
     * The permissions someone needs to give a key this scope, so a key never does more than its creator could in the panel.
     *
     * @return list<Permission>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::EmailsSend, self::EmailsSendMarketing => [Permission::ComposeEmail],
            self::EmailsRead => [Permission::ViewActivity],
            self::EmailsReadAll => [Permission::ViewActivity, Permission::ExportActivity],
            self::ProspectsRead => [Permission::ViewProspects, Permission::ExportProspects],
            self::ProspectsWrite => [Permission::ViewProspects, Permission::ExportProspects, Permission::ManageProspects, Permission::ImportProspects],
            self::CampaignsRead => [Permission::ViewCampaigns],
            self::CampaignsWrite => [Permission::ViewCampaigns, Permission::ManageCampaigns],
            self::ConversationsRead => [Permission::ViewInbox, Permission::ViewAllConversations],
        };
    }

    /**
     * Scopes that change real data. Test keys never get them: their changes would not be simulated.
     */
    public function changesData(): bool
    {
        return in_array($this, [self::ProspectsWrite, self::CampaignsWrite], true);
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EmailsSend => 'Send transactional email.',
            self::EmailsSendMarketing => 'Send marketing email. Consent, unsubscribe and suppression rules still apply.',
            self::EmailsRead => 'Read emails this key sent.',
            self::EmailsReadAll => 'Read every email, including those sent from the panel and by other keys.',
            self::ProspectsRead => 'Read prospects.',
            self::ProspectsWrite => 'Create and update prospects and record unsubscribes (includes prospects:read). Never removes a suppression.',
            self::CampaignsRead => 'Read campaigns and their results.',
            self::CampaignsWrite => 'Add prospects to draft campaigns (includes campaigns:read). Approval and sending stay in the panel.',
            self::ConversationsRead => 'Read inbox conversations and replies.',
        };
    }
}
