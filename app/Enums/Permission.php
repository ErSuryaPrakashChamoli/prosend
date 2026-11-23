<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Granular abilities a role can grant inside a client. Super Admins have every permission in every client.
 */
enum Permission: string implements HasDescription, HasLabel
{
    case ViewProspects = 'prospects.view';
    case ManageProspects = 'prospects.manage';
    case ImportProspects = 'prospects.import';
    case ExportProspects = 'prospects.export';
    case ViewCampaigns = 'campaigns.view';
    case ManageCampaigns = 'campaigns.manage';
    case OperateCampaigns = 'campaigns.operate';
    case ApproveCampaigns = 'campaigns.approve';
    case ManageTemplates = 'templates.manage';
    case ComposeEmail = 'email.compose';
    case ViewActivity = 'activity.view';
    case ExportActivity = 'activity.export';
    case ViewInbox = 'inbox.view';
    case ViewAllConversations = 'inbox.view_all';
    case ReplyInbox = 'inbox.reply';
    case AssignConversations = 'inbox.assign';
    case ViewReports = 'reports.view';
    case ExportReports = 'reports.export';
    case ViewTasks = 'tasks.view';
    case ManageTasks = 'tasks.manage';
    case ManageAutomations = 'automations.manage';
    case ManageMailboxes = 'mailboxes.manage';
    case ManageDeliverability = 'deliverability.manage';
    case ManageSuppressions = 'suppressions.manage';
    case ManageIntegrations = 'integrations.manage';
    case ManageTeam = 'team.manage';
    case ViewAuditLog = 'audit.view';
    case ManageSettings = 'settings.manage';

    public function getLabel(): string
    {
        return match ($this) {
            self::ViewProspects => 'View prospects, lists and segments',
            self::ManageProspects => 'Add, edit and delete prospects, lists and segments',
            self::ImportProspects => 'Import prospects',
            self::ExportProspects => 'Export prospects',
            self::ViewCampaigns => 'View campaigns',
            self::ManageCampaigns => 'Create, edit and submit campaigns',
            self::OperateCampaigns => 'Pause, resume, stop and retry campaigns',
            self::ApproveCampaigns => 'Approve campaigns',
            self::ManageTemplates => 'Manage email templates',
            self::ComposeEmail => 'Compose individual emails',
            self::ViewActivity => 'View email activity',
            self::ExportActivity => 'Export email activity',
            self::ViewInbox => 'Use the inbox',
            self::ViewAllConversations => 'See every conversation',
            self::ReplyInbox => 'Reply and update conversations',
            self::AssignConversations => 'Assign conversations',
            self::ViewReports => 'See reports',
            self::ExportReports => 'Export reports',
            self::ViewTasks => 'See tasks',
            self::ManageTasks => 'Create, assign and complete tasks',
            self::ManageAutomations => 'Manage automation rules',
            self::ManageMailboxes => 'Manage mailboxes and sender identities',
            self::ManageDeliverability => 'Manage domains and warm-up',
            self::ManageSuppressions => 'Remove suppressions',
            self::ManageIntegrations => 'Manage API keys and webhooks',
            self::ManageTeam => 'Manage members and teams',
            self::ViewAuditLog => 'View the audit log',
            self::ManageSettings => 'Change client settings',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ViewProspects => 'Read-only access to the audience.',
            self::ManageProspects => 'Includes tags, stages, owners, and adding addresses to the do-not-contact list.',
            self::ImportProspects => 'CSV, Excel and pasted addresses.',
            self::ExportProspects => 'Download prospect data as CSV.',
            self::ViewCampaigns => 'Read-only access to campaigns and their results.',
            self::ManageCampaigns => 'Drafts, duplicates and submission for approval.',
            self::OperateCampaigns => 'Also sends test emails and retries failed emails.',
            self::ApproveCampaigns => 'Starts submitted campaigns. With a second approver required, never one\'s own.',
            self::ManageTemplates => 'Create, edit and restore template versions.',
            self::ComposeEmail => 'Send one-off emails to up to 50 recipients.',
            self::ViewActivity => 'Every email sent for this client, with its events.',
            self::ExportActivity => 'Download email activity and campaign recipients as CSV.',
            self::ViewInbox => 'Without "See every conversation", only conversations assigned to them or with prospects they own.',
            self::ViewAllConversations => 'Every reply in the client, whoever it is assigned to.',
            self::ReplyInbox => 'Reply, change status, star, and add internal notes.',
            self::AssignConversations => 'Give conversations to colleagues.',
            self::ViewReports => 'Campaign, people, team, mailbox and daily results.',
            self::ExportReports => 'Download reports as CSV or Excel, or print them as PDF.',
            self::ViewTasks => 'Every task in the client; without it, tasks assigned to the person are still listed.',
            self::ManageTasks => 'Including tasks for colleagues.',
            self::ManageAutomations => 'Rules that tag, assign, notify, create tasks, stop sequences, send email or call webhooks when something happens.',
            self::ManageMailboxes => 'SMTP, IMAP and API credentials, sender verification and delivery webhooks.',
            self::ManageDeliverability => 'SPF, DKIM and DMARC checks and warm-up plans.',
            self::ManageSuppressions => 'Lets the address receive email again. Adding suppressions only needs prospect management.',
            self::ManageIntegrations => 'Create, rotate and revoke API keys; add outgoing webhooks and see their delivery log.',
            self::ManageTeam => 'Invite people to this client, change their roles, and manage teams.',
            self::ViewAuditLog => 'Who changed what, for this client.',
            self::ManageSettings => 'The postal address in marketing footers, and defaults for new campaigns.',
        };
    }

    /**
     * Permissions grouped for the role editor.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        return [
            'Prospects' => [self::ViewProspects, self::ManageProspects, self::ImportProspects, self::ExportProspects],
            'Campaigns' => [self::ViewCampaigns, self::ManageCampaigns, self::OperateCampaigns, self::ApproveCampaigns, self::ManageTemplates, self::ComposeEmail, self::ViewActivity, self::ExportActivity],
            'Inbox' => [self::ViewInbox, self::ViewAllConversations, self::ReplyInbox, self::AssignConversations],
            'Reports' => [self::ViewReports, self::ExportReports],
            'Tasks and automation' => [self::ViewTasks, self::ManageTasks, self::ManageAutomations],
            'Sending setup' => [self::ManageMailboxes, self::ManageDeliverability, self::ManageSuppressions, self::ManageIntegrations],
            'Administration' => [self::ManageTeam, self::ViewAuditLog, self::ManageSettings],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
