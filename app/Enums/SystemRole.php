<?php

namespace App\Enums;

/**
 * The roles every installation starts with. They are stored in the roles table, where their permissions can be
 * changed, except Admin's: each client always has a role with full access.
 */
enum SystemRole: string
{
    case Admin = 'admin';
    case TeamLeader = 'team_leader';
    case CampaignManager = 'campaign_manager';
    case SalesExecutive = 'sales_executive';
    case Analyst = 'analyst';
    case GuestViewer = 'guest_viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::TeamLeader => 'Team Leader',
            self::CampaignManager => 'Campaign Manager',
            self::SalesExecutive => 'Sales Executive',
            self::Analyst => 'Analyst',
            self::GuestViewer => 'Guest Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access to this client, including mailboxes, members and the audit log.',
            self::TeamLeader => 'Runs campaigns and approves them.',
            self::CampaignManager => 'Manages prospects, templates and campaigns, and submits campaigns for approval.',
            self::SalesExecutive => 'Works with prospects and sends individual emails.',
            self::Analyst => 'Reads everything and exports prospect data.',
            self::GuestViewer => 'Read-only access.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        $campaignManager = [
            Permission::ViewProspects,
            Permission::ManageProspects,
            Permission::ImportProspects,
            Permission::ExportProspects,
            Permission::ViewCampaigns,
            Permission::ManageCampaigns,
            Permission::OperateCampaigns,
            Permission::ManageTemplates,
            Permission::ComposeEmail,
            Permission::ViewActivity,
            Permission::ExportActivity,
            Permission::ViewInbox,
            Permission::ViewAllConversations,
            Permission::ReplyInbox,
            Permission::AssignConversations,
            Permission::ViewTasks,
            Permission::ManageTasks,
            Permission::ManageAutomations,
            Permission::ViewReports,
            Permission::ExportReports,
        ];

        return match ($this) {
            self::Admin => Permission::cases(),
            self::TeamLeader => [...$campaignManager, Permission::ApproveCampaigns],
            self::CampaignManager => $campaignManager,
            // Exporting email activity was open to every role before clients existed, so every role keeps it.
            // Sales Executives see only conversations assigned to them or with prospects they own.
            self::SalesExecutive => [Permission::ViewProspects, Permission::ManageProspects, Permission::ViewCampaigns, Permission::ComposeEmail, Permission::ViewActivity, Permission::ExportActivity, Permission::ViewInbox, Permission::ReplyInbox, Permission::ManageTasks, Permission::ViewReports],
            self::Analyst => [Permission::ViewProspects, Permission::ExportProspects, Permission::ViewCampaigns, Permission::ViewActivity, Permission::ExportActivity, Permission::ViewInbox, Permission::ViewAllConversations, Permission::ViewTasks, Permission::ViewReports, Permission::ExportReports],
            self::GuestViewer => [Permission::ViewProspects, Permission::ViewCampaigns, Permission::ViewActivity, Permission::ExportActivity, Permission::ViewInbox, Permission::ViewAllConversations, Permission::ViewReports],
        };
    }

    /**
     * The client role given to accounts that had a role before clients existed. Administrators became Super Admins.
     */
    public static function forLegacyRole(UserRole $role): self
    {
        return match ($role) {
            UserRole::Admin => self::Admin,
            UserRole::Sender => self::CampaignManager,
            UserRole::Viewer => self::GuestViewer,
        };
    }
}
