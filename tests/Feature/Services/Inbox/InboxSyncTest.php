<?php

use App\Enums\BounceType;
use App\Enums\CampaignStatus;
use App\Enums\ContactStage;
use App\Enums\ConversationStatus;
use App\Enums\EmailEventType;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\SequenceStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\EmailMessage;
use App\Models\InboundMailbox;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Inbox\InboxSynchronizer;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailboxReader;
use Tests\Fakes\FakeMailProviderDriver;

/**
 * @param  array<string, string>  $headers
 */
function rawEmail(array $headers, string $body = 'Thanks, sounds interesting.'): string
{
    $headers = ['From' => 'Jane Doe <jane@example.com>', 'To' => 'sales@acme.test', 'Subject' => 'Re: Quick question', 'Date' => now()->toRfc2822String(), 'Message-ID' => '<'.Str::uuid().'@example.com>', 'Content-Type' => 'text/plain; charset=utf-8', ...$headers];

    return collect($headers)->filter()->map(fn (string $value, string $name): string => "{$name}: {$value}")->implode("\r\n")."\r\n\r\n{$body}\r\n";
}

/**
 * A campaign email to Jane that was accepted, with Jane still in the sequence.
 *
 * @return array{0: EmailMessage, 1: Contact, 2: InboundMailbox}
 */
function sentToJane(): array
{
    $owner = User::factory()->create();
    $contact = Contact::factory()->consented()->create(['email' => 'jane@example.com', 'owner_id' => $owner->id, 'stage' => ContactStage::Contacted]);
    $campaign = Campaign::factory()->prepared()->create(['subject' => 'Quick question']);
    $message = EmailMessage::factory()->for($campaign)->for($contact)->accepted()->create([
        'to_email' => 'jane@example.com',
        'source' => MessageSource::Campaign,
        'provider_message_id' => 'abc-123@acme.test',
    ]);
    CampaignProspect::query()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'current_step' => 1, 'next_step_due_at' => now()->addDays(2)]);

    return [$message, $contact, InboundMailbox::factory()->create()];
}

function syncMailbox(InboundMailbox $mailbox): array
{
    return app(InboxSynchronizer::class)->sync($mailbox->refresh());
}

it('turns a reply into an unread conversation, records the reply and ends the sequence', function () {
    [$message, $contact, $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'References' => '<abc-123@acme.test>']));

    expect(syncMailbox($mailbox))->toBe(['reply' => 1]);

    $conversation = Conversation::query()->sole();
    expect($conversation)
        ->contact_id->toBe($contact->id)
        ->campaign_id->toBe($message->campaign_id)
        ->sender_identity_id->toBe($message->sender_identity_id)
        ->assigned_to->toBe($contact->owner_id)
        ->subject->toBe('Quick question')
        ->status->toBe(ConversationStatus::New)
        ->is_read->toBeFalse();
    expect($conversation->messages()->sole())->from_email->toBe('jane@example.com')->text_body->toContain('sounds interesting');
    expect($contact->refresh())->stage->toBe(ContactStage::Replied)->last_replied_at->not->toBeNull();
    expect(CampaignProspect::query()->sole()->status)->toBe(SequenceStatus::Replied);
    expect($message->events()->where('type', EmailEventType::Replied)->exists())->toBeTrue();
    expect($mailbox->refresh())->last_uid->toBe(1)->health_status->toBe(ProviderHealth::Healthy);
});

it('recognises a reply without threading headers by a prospect emailed recently', function () {
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['Subject' => 'Your email']));

    expect(syncMailbox($mailbox))->toBe(['reply' => 1]);
});

it('leaves email that is not a reply to ProSend alone', function () {
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['From' => 'news@shop.test', 'Subject' => 'Weekly deals']));

    expect(syncMailbox($mailbox))->toBe(['ignored' => 1])
        ->and(Conversation::query()->count())->toBe(0);
});

it('keeps an automatic reply without ending the sequence or marking anything unread', function () {
    [, $contact, $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Auto-Submitted' => 'auto-replied', 'Subject' => 'Out of office']));

    expect(syncMailbox($mailbox))->toBe(['auto_reply' => 1]);

    expect(Conversation::query()->sole()->messages()->sole()->is_auto_reply)->toBeTrue();
    expect(CampaignProspect::query()->sole()->status)->toBe(SequenceStatus::Active)
        ->and($contact->refresh()->last_replied_at)->toBeNull();
});

it('never processes the same email twice, even when the server resets its UIDs', function () {
    [, , $mailbox] = sentToJane();
    $reader = FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Message-ID' => '<once@example.com>']));
    syncMailbox($mailbox);

    $reader->uidValidity = 2;

    expect(syncMailbox($mailbox))->toBe(['duplicate' => 1])
        ->and(Conversation::query()->sole()->messages()->count())->toBe(1);
});

it('adds later replies to the same conversation and reopens a closed one', function () {
    [, , $mailbox] = sentToJane();
    $reader = FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>']));
    syncMailbox($mailbox);
    Conversation::query()->sole()->forceFill(['status' => ConversationStatus::Closed, 'is_read' => true])->save();

    $reader->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>'], 'One more thing.'));
    syncMailbox($mailbox);

    expect(Conversation::query()->sole())
        ->status->toBe(ConversationStatus::Open)
        ->is_read->toBeFalse()
        ->messages->toHaveCount(2);
});

it('turns a permanent delivery failure report into a hard bounce that suppresses the address and ends the sequence', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(implode("\r\n", [
        'From: Mail Delivery System <MAILER-DAEMON@mx.example.net>',
        'To: sales@acme.test',
        'Subject: Undelivered Mail Returned to Sender',
        'Message-ID: <dsn-1@mx.example.net>',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="r1"',
        '',
        '--r1',
        'Content-Type: text/plain',
        '',
        'The mail could not be delivered.',
        '--r1',
        'Content-Type: message/delivery-status',
        '',
        'Final-Recipient: rfc822; jane@example.com',
        'Action: failed',
        'Status: 5.1.1',
        'Diagnostic-Code: smtp; 550 5.1.1 user unknown',
        '--r1',
        'Content-Type: text/rfc822-headers',
        '',
        'Message-ID: <abc-123@acme.test>',
        'Subject: Quick question',
        '--r1--',
        '',
    ]));

    expect(syncMailbox($mailbox))->toBe(['bounce' => 1]);

    expect($message->refresh())->status->toBe(MessageStatus::Bounced)->bounce_type->toBe(BounceType::Hard);
    expect(Suppression::query()->sole())->email->toBe('jane@example.com')->reason->toBe(SuppressionReason::HardBounce);
    expect(CampaignProspect::query()->sole()->status)->toBe(SequenceStatus::Bounced)
        ->and(Conversation::query()->count())->toBe(0);
});

it('records a temporary failure as a soft bounce without suppressing', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver("From: postmaster@mx.example.net\r\nTo: sales@acme.test\r\nSubject: Delivery Status Notification (Delay)\r\nMessage-ID: <dsn-2@mx.example.net>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"r1\"\r\n\r\n--r1\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822; jane@example.com\r\nAction: delayed\r\nStatus: 4.2.2\r\n--r1\r\nContent-Type: text/rfc822-headers\r\n\r\nMessage-ID: <abc-123@acme.test>\r\n--r1--\r\n");

    syncMailbox($mailbox);

    expect($message->refresh()->status)->toBe(MessageStatus::SoftBounced)
        ->and(Suppression::query()->count())->toBe(0);
});

it('stores attachments privately', function () {
    Storage::fake('local');
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(implode("\r\n", [
        'From: Jane Doe <jane@example.com>',
        'To: sales@acme.test',
        'Subject: Re: Quick question',
        'In-Reply-To: <abc-123@acme.test>',
        'Message-ID: <att@example.com>',
        'Content-Type: multipart/mixed; boundary="m1"',
        '',
        '--m1',
        'Content-Type: text/plain',
        '',
        'Brochure attached.',
        '--m1',
        'Content-Type: application/pdf; name="brochure.pdf"',
        'Content-Disposition: attachment; filename="brochure.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('%PDF-1.4 test'),
        '--m1--',
        '',
    ]));

    syncMailbox($mailbox);

    $attachment = Conversation::query()->sole()->messages()->sole()->attachments[0];
    expect($attachment)->name->toBe('brochure.pdf')->size->toBe(13);
    Storage::disk('local')->assertExists($attachment['path']);
});

it('records a failed connection on the mailbox without moving past any email', function () {
    [, , $mailbox] = sentToJane();
    $reader = FakeMailboxReader::install();
    $reader->failure = new RuntimeException('Authentication failed');

    expect(syncMailbox($mailbox))->toBe([]);
    expect($mailbox->refresh())->health_status->toBe(ProviderHealth::Failing)->last_error->toContain('Authentication failed')->last_uid->toBeNull();
});

it('never matches a reply in one client\'s mailbox to another client\'s email', function () {
    sentToJane();
    $otherMailbox = asClient(Client::factory()->create(), fn () => InboundMailbox::factory()->create());
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>']));

    expect(syncMailbox($otherMailbox))->toBe(['ignored' => 1])
        ->and(Conversation::acrossClients()->count())->toBe(0)
        ->and(CampaignProspect::query()->sole()->status)->toBe(SequenceStatus::Active);
});

it('cancels a follow-up that was queued before the reply arrived', function () {
    FakeMailProviderDriver::install();
    [$message, , $mailbox] = sentToJane();
    $followUp = EmailMessage::factory()->for($message->campaign)->for($message->contact)->create(['to_email' => 'jane@example.com', 'step_number' => 2]);
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>']));

    syncMailbox($mailbox);
    app()->call([new SendEmailMessage($followUp), 'handle']);

    expect($followUp->refresh()->status)->toBe(MessageStatus::Cancelled)
        ->and($message->campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

function deliveryReport(string $from, string $finalRecipient, string $action = 'failed', string $status = '5.1.1', string $returnPath = ''): string
{
    return implode("\r\n", array_filter([
        $returnPath === '' ? null : "Return-Path: {$returnPath}",
        "From: {$from}",
        'To: sales@acme.test',
        'Subject: Delivery Status Notification',
        'Message-ID: <'.Str::uuid().'@mx.example.net>',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="r1"',
        '',
        '--r1',
        'Content-Type: message/delivery-status',
        '',
        "Final-Recipient: rfc822; {$finalRecipient}",
        "Action: {$action}",
        "Status: {$status}",
        '--r1',
        'Content-Type: text/rfc822-headers',
        '',
        'Message-ID: <abc-123@acme.test>',
        '--r1--',
        '',
    ], fn (?string $line): bool => $line !== null));
}

it('ignores a bounce report that names a different address than the email went to', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(deliveryReport('MAILER-DAEMON@mx.example.net', 'ceo@example.com'));

    syncMailbox($mailbox);

    expect(Suppression::query()->count())->toBe(0)
        ->and($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('does not treat a report written by a person as a bounce', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(deliveryReport('Jane Doe <jane@example.com>', 'jane@example.com', returnPath: '<jane@example.com>'));

    syncMailbox($mailbox);

    expect(Suppression::query()->count())->toBe(0)
        ->and($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('accepts a report with an empty return path as coming from a mail system', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(deliveryReport('bounces@mx.example.net', 'jane@example.com', returnPath: '<>'));

    expect(syncMailbox($mailbox))->toBe(['bounce' => 1])
        ->and($message->refresh()->status)->toBe(MessageStatus::Bounced);
});

it('does not count a successful delivery report as a bounce', function () {
    [$message, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(deliveryReport('MAILER-DAEMON@mx.example.net', 'jane@example.com', 'delivered', '2.0.0'));

    syncMailbox($mailbox);

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

it('starts over when the server resets its UIDs, even if no email arrived', function () {
    [, , $mailbox] = sentToJane();
    $mailbox->forceFill(['last_uid' => 5, 'uid_validity' => 1])->save();
    $reader = FakeMailboxReader::install();
    $reader->uidValidity = 2;

    syncMailbox($mailbox);

    expect($mailbox->refresh())->last_uid->toBeNull()->uid_validity->toBe(2);
});

it('ignores mail from the team itself, such as a colleague\'s reply-all', function () {
    [, , $mailbox] = sentToJane();
    $colleague = User::factory()->create(['email' => 'rep@acme.test']);
    FakeMailboxReader::install()->deliver(rawEmail(['From' => "Rep <{$colleague->email}>", 'In-Reply-To' => '<abc-123@acme.test>']));

    expect(syncMailbox($mailbox))->toBe(['ignored' => 1])
        ->and(CampaignProspect::query()->sole()->status)->toBe(SequenceStatus::Active);
});

it('processes an email that reached two of the client\'s mailboxes once', function () {
    [, , $mailbox] = sentToJane();
    $second = InboundMailbox::factory()->create();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Message-ID' => '<both@example.com>']));

    syncMailbox($mailbox);

    expect(syncMailbox($second))->toBe(['duplicate' => 1]);
});

it('keeps a conversation that only holds an automatic reply out of the unread count', function () {
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Auto-Submitted' => 'auto-replied']));

    syncMailbox($mailbox);

    expect(Conversation::query()->sole()->is_read)->toBeTrue();
});

it('records a reply at the time ProSend read it, whatever the email\'s date says', function () {
    $this->freezeSecond();
    [, $contact, $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Date' => 'Mon, 1 Jan 2001 10:00:00 +0000']));

    syncMailbox($mailbox);

    expect($contact->refresh()->last_replied_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('survives an email with a broken date', function () {
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Date' => 'not a date']));

    expect(syncMailbox($mailbox))->toBe(['reply' => 1]);
});

it('escapes the subject in the assignee\'s notification', function () {
    [, , $mailbox] = sentToJane();
    FakeMailboxReader::install()->deliver(rawEmail(['In-Reply-To' => '<abc-123@acme.test>', 'Subject' => 'Re: <a href="https://evil.test">Click</a>']));

    syncMailbox($mailbox);

    $notification = DatabaseNotification::query()->sole();
    expect($notification->data['title'])->toContain('&lt;a href=')->not->toContain('<a href=');
});

it('shows no sent emails in a conversation without a prospect', function () {
    EmailMessage::factory()->accepted()->create(['contact_id' => null, 'subject' => 'Someone else\'s receipt']);
    $conversation = Conversation::factory()->create(['contact_id' => null]);

    expect($conversation->sentEmails()->count())->toBe(0);
});
