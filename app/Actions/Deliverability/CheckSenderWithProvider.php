<?php

namespace App\Actions\Deliverability;

use App\Enums\ProviderType;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Providers\Brevo\BrevoApiClient;
use App\Services\Providers\Brevo\BrevoApiException;
use Illuminate\Support\Str;

/**
 * Sets a sender identity's verification from what Brevo reports, instead of an administrator's word.
 */
class CheckSenderWithProvider
{
    public function __construct(
        private BrevoApiClient $brevo,
        private AuditLogger $auditLogger,
    ) {}

    public static function isAvailable(SenderIdentity $senderIdentity): bool
    {
        $provider = $senderIdentity->smtpProvider;

        return $provider?->type === ProviderType::Brevo && filled($provider->api_key);
    }

    /**
     * Returns a sentence describing the result.
     *
     * @throws BrevoApiException
     */
    public function handle(SenderIdentity $senderIdentity, User $user): string
    {
        $senders = $this->brevo->senders($senderIdentity->smtpProvider, $senderIdentity->domain());
        $match = collect($senders)->first(fn (array $sender): bool => Str::lower($sender['email']) === $senderIdentity->email);

        if ($match === null) {
            if ($senderIdentity->isVerified()) {
                $senderIdentity->revokeVerification();
                $this->auditLogger->record('sender.verification_removed', $senderIdentity, ['email' => $senderIdentity->email, 'reason' => 'Not found in Brevo']);
            }

            return "{$senderIdentity->email} is not a sender in Brevo. Add it under Senders, Domains & Dedicated IPs > Senders.";
        }

        if (! $match['active']) {
            if ($senderIdentity->isVerified()) {
                $senderIdentity->revokeVerification();
                $this->auditLogger->record('sender.verification_removed', $senderIdentity, ['email' => $senderIdentity->email, 'reason' => 'Inactive in Brevo']);
            }

            return "{$senderIdentity->email} is in Brevo but not activated yet. Confirm the verification email Brevo sent to that address.";
        }

        if (! $senderIdentity->isVerified()) {
            $senderIdentity->markVerified($user);
            $this->auditLogger->record('sender.verified', $senderIdentity, ['email' => $senderIdentity->email, 'source' => 'Brevo API']);
        }

        return "Brevo confirms {$senderIdentity->email} is an active sender.";
    }
}
