<?php

namespace App\Services\Providers;

use App\Models\EmailMessage;

class BrevoDriver extends SmtpDriver
{
    /**
     * Brevo returns the X-Mailin-custom header in webhook payloads, which lets events
     * be matched even if the Message-ID is rewritten.
     */
    public function headersFor(EmailMessage $message): array
    {
        return ['X-Mailin-custom' => $message->uuid];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(deliveryWebhooks: true, engagementTracking: true);
    }
}
