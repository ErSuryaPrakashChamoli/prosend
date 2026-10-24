<?php

namespace App\Services\Sending;

use App\Enums\EmailPurpose;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\Suppression;
use Illuminate\Support\Str;

class RecipientEligibility
{
    /**
     * Why the address may not receive email of the given purpose, or null when it may.
     */
    public function blockReason(string $email, EmailPurpose $purpose): ?string
    {
        $email = Str::lower(trim($email));

        $suppression = Suppression::blockingFor($email, $purpose);

        if ($suppression) {
            return "suppressed ({$suppression->reason->getLabel()})";
        }

        if ($purpose !== EmailPurpose::Marketing) {
            return null;
        }

        $contact = Contact::query()->where('email', $email)->first();

        if (! $contact) {
            return 'not a contact with recorded marketing consent';
        }

        return $contact->ineligibilityReason($purpose);
    }

    public function blockReasonForMessage(EmailMessage $message): ?string
    {
        foreach ($message->allRecipients() as $email) {
            $reason = $this->blockReason($email, $message->purpose);

            if ($reason !== null) {
                return "{$email}: {$reason}";
            }
        }

        return null;
    }
}
