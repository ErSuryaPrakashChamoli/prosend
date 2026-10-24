<?php

use App\Actions\Messages\QueueComposedEmails;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function composedEmail(array $overrides = []): array
{
    return [
        'sender_identity_id' => SenderIdentity::factory()->create()->id,
        'purpose' => EmailPurpose::Transactional->value,
        'to' => ['jane@example.com'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Your account',
        'body' => '<p>Hello</p>',
        ...$overrides,
    ];
}

it('queues a separate email for each recipient', function () {
    Queue::fake([SendEmailMessage::class]);
    $user = User::factory()->sender()->create();
    $contact = Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe']);

    $messages = app(QueueComposedEmails::class)->handle(composedEmail(['to' => ['Jane@Example.com', 'sam@example.com']]), $user);

    expect($messages)->toHaveCount(2);
    expect(EmailMessage::query()->orderBy('id')->get(['to_email', 'to_name', 'contact_id', 'status', 'created_by'])->toArray())->toBe([
        ['to_email' => 'jane@example.com', 'to_name' => 'Jane Doe', 'contact_id' => $contact->id, 'status' => MessageStatus::Queued->value, 'created_by' => $user->id],
        ['to_email' => 'sam@example.com', 'to_name' => null, 'contact_id' => null, 'status' => MessageStatus::Queued->value, 'created_by' => $user->id],
    ]);
    Queue::assertPushed(SendEmailMessage::class, 2);
});

it('delays the send until the scheduled time', function () {
    Queue::fake([SendEmailMessage::class]);
    $this->freezeSecond();

    app(QueueComposedEmails::class)->handle(composedEmail(['scheduled_at' => now()->addHour()->toDateTimeString()]), User::factory()->create());

    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->delay->equalTo(now()->addHour()));
});

it('rejects suppressed recipients and names the reason', function () {
    Queue::fake([SendEmailMessage::class]);
    Suppression::factory()->create(['email' => 'gone@example.com', 'reason' => SuppressionReason::HardBounce]);

    try {
        app(QueueComposedEmails::class)->handle(composedEmail(['to' => ['gone@example.com']]), User::factory()->create());
        $this->fail('The email was queued.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['to' => ['These recipients cannot receive this email: gone@example.com (suppressed (Hard bounce)).']]);
    }

    expect(EmailMessage::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailMessage::class);
});

it('rejects marketing email to an address without recorded consent', function () {
    Contact::factory()->create(['email' => 'lead@example.com']);

    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'purpose' => EmailPurpose::Marketing->value,
        'to' => ['lead@example.com'],
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'lead@example.com (no marketing consent recorded)');
});

it('rejects CC when sending to more than one recipient', function () {
    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'to' => ['a@example.com', 'b@example.com'],
        'cc' => ['c@example.com'],
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'CC and BCC can only be used when sending to one recipient');
});

it('rejects an address that appears in both To and BCC', function () {
    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'to' => ['a@example.com'],
        'bcc' => ['A@example.com'],
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'These addresses appear more than once: a@example.com.');
});

it('rejects an attachment path outside the attachments folder', function () {
    Storage::fake('local');
    Storage::disk('local')->put('secrets.txt', 'private');

    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'attachments' => ['../secrets.txt'],
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'An attachment could not be found. Upload it again.');
});

it('attaches uploaded files with their original names', function () {
    Queue::fake([SendEmailMessage::class]);
    Storage::fake('local');
    Storage::disk('local')->put('email-attachments/abc123.pdf', '%PDF');

    app(QueueComposedEmails::class)->handle(composedEmail([
        'attachments' => ['email-attachments/abc123.pdf'],
        'attachment_names' => ['email-attachments/abc123.pdf' => 'Invoice.pdf'],
    ]), User::factory()->create());

    expect(EmailMessage::query()->sole()->attachments)->toBe([['path' => 'email-attachments/abc123.pdf', 'name' => 'Invoice.pdf']]);
});

it('rejects an unverified sender when verification is required', function () {
    config(['prosend.require_verified_senders' => true]);

    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'sender_identity_id' => SenderIdentity::factory()->unverified()->create()->id,
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'This sender must be verified with the provider before it can send.');
});

it('rejects recipients missing a value the template requires', function () {
    Contact::factory()->create(['email' => 'jane@example.com', 'custom_attributes' => null]);
    $template = EmailTemplate::factory()->transactional()->create(['required_variables' => ['action_url'], 'body' => '<p>{{ action_url }}</p>']);

    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'email_template_id' => $template->id,
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'These recipients are missing values the template requires: jane@example.com (missing action_url).');
});

it('rejects a marketing template for transactional email', function () {
    $template = EmailTemplate::factory()->create();

    expect(fn () => app(QueueComposedEmails::class)->handle(composedEmail([
        'email_template_id' => $template->id,
    ]), User::factory()->create()))->toThrow(ValidationException::class, 'This is a Marketing template and cannot be used for Transactional email.');
});
