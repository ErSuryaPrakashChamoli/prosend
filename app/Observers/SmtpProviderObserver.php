<?php

namespace App\Observers;

use App\Models\SmtpProvider;
use App\Services\AuditLogger;

/**
 * Audits provider changes. Secrets are never logged, only the fact that they changed.
 */
class SmtpProviderObserver
{
    /**
     * Fields that change during normal operation and are not configuration changes.
     */
    /**
     * Fields that change in normal operation, not by anyone's decision. OAuth tokens are refreshed about every hour;
     * connecting a mailbox is audited on its own ("mailbox.connected").
     */
    private const OPERATIONAL_FIELDS = ['updated_at', 'health_status', 'last_checked_at', 'last_connected_at', 'last_error', 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at'];

    private const SECRET_FIELDS = ['password', 'api_key', 'webhook_token_hash'];

    public function __construct(private AuditLogger $auditLogger) {}

    public function created(SmtpProvider $smtpProvider): void
    {
        $this->auditLogger->record('provider.created', $smtpProvider, [
            'name' => $smtpProvider->name,
            'host' => $smtpProvider->host,
        ]);
    }

    public function updated(SmtpProvider $smtpProvider): void
    {
        $changed = array_diff(array_keys($smtpProvider->getChanges()), self::OPERATIONAL_FIELDS);

        if ($changed === []) {
            return;
        }

        $this->auditLogger->record('provider.updated', $smtpProvider, [
            'changed_fields' => array_values(array_diff($changed, self::SECRET_FIELDS)),
            'password_changed' => in_array('password', $changed, true),
            'api_key_changed' => in_array('api_key', $changed, true),
            'webhook_token_changed' => in_array('webhook_token_hash', $changed, true),
        ]);
    }

    public function deleted(SmtpProvider $smtpProvider): void
    {
        $this->auditLogger->record('provider.deleted', $smtpProvider, ['name' => $smtpProvider->name]);
    }
}
