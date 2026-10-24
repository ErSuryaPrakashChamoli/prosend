<?php

namespace App\Actions\Messages;

use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sends a test copy to the signed-in user immediately, so the result can be shown straight away.
 * Test emails are transactional, so they skip marketing consent checks but not suppressions.
 */
class SendTestEmail
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, User $user): EmailMessage
    {
        $validated = Validator::make($data, [
            'sender_identity_id' => ['required', 'integer', Rule::exists(SenderIdentity::class, 'id')],
            'subject' => ['required', 'string', 'max:240'],
            'body' => ['required', 'string', 'max:500000'],
            'text_body' => ['nullable', 'string', 'max:500000'],
        ], [
            'sender_identity_id.required' => 'Choose a sender identity first.',
        ])->validate();

        $message = EmailMessage::query()->create([
            'sender_identity_id' => $validated['sender_identity_id'],
            'created_by' => $user->getKey(),
            'purpose' => EmailPurpose::Transactional,
            'source' => MessageSource::Test,
            'status' => MessageStatus::Queued,
            'to_email' => $user->email,
            'to_name' => $user->name,
            'subject' => '[Test] '.$validated['subject'],
            'html_body' => $validated['body'],
            'text_body' => $validated['text_body'] ?? null,
            'queued_at' => now(),
        ]);

        $message->recordEvent(EmailEventType::Queued, details: ['reason' => 'Test email']);

        SendEmailMessage::dispatchSync($message);

        $message->refresh();

        // A synchronous job cannot be retried later, so a test that would have waited is reported as failed.
        if ($message->status->isAwaitingSend()) {
            $message->markFailed($message->error_message ?? 'The test email could not be sent right now.', $message->error_code, retryable: true);
        }

        return $message;
    }
}
