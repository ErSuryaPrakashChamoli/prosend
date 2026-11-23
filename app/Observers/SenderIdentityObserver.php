<?php

namespace App\Observers;

use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Services\AuditLogger;

/**
 * Audits which provider a sender identity sends through, since that decides where its email goes.
 */
class SenderIdentityObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updated(SenderIdentity $senderIdentity): void
    {
        if (! $senderIdentity->wasChanged('smtp_provider_id')) {
            return;
        }

        $from = SmtpProvider::query()->find($senderIdentity->getOriginal('smtp_provider_id'));
        $to = $senderIdentity->smtpProvider()->first();

        $this->auditLogger->record('sender.provider_changed', $senderIdentity, [
            'email' => $senderIdentity->email,
            'from' => $from?->name,
            'to' => $to?->name,
            'from_sandbox' => $from?->isSandbox(),
            'to_sandbox' => $to?->isSandbox(),
        ]);
    }
}
