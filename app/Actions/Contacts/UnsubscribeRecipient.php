<?php

namespace App\Actions\Contacts;

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\Suppression;

class UnsubscribeRecipient
{
    /**
     * Stop marketing email to the message's recipient. Safe to call more than once.
     */
    public function handle(EmailMessage $message, EventSource $source = EventSource::Recipient): void
    {
        $email = $message->to_email;

        Suppression::record($email, SuppressionReason::Unsubscribed, $source->value);

        Contact::query()
            ->where('email', $email)
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now()]);

        if (! $message->events()->where('type', EmailEventType::Unsubscribed)->exists()) {
            $message->recordEvent(EmailEventType::Unsubscribed, $source, recipientEmail: $email);
        }
    }
}
