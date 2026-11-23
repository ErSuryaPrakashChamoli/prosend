<?php

use App\Enums\ApiKeyMode;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProviderDriver;

function sendPayload(array $overrides = []): array
{
    return [
        'from' => 'billing@acme.test',
        'to' => [['email' => 'Jane@Example.com', 'name' => 'Jane Doe']],
        'purpose' => 'transactional',
        'subject' => 'Your receipt',
        'html' => '<p>Thanks for your order.</p>',
        ...$overrides,
    ];
}

it('queues one message per recipient and returns 202', function () {
    Queue::fake([SendEmailMessage::class]);
    [$apiKey, $plaintext] = issueApiKey();

    $response = $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'to' => [['email' => 'jane@example.com', 'name' => 'Jane Doe'], ['email' => 'sam@example.com']],
        'tags' => ['receipt'],
        'metadata' => ['order_id' => 1042],
    ]));

    $response->assertAccepted()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'queued')
        ->assertJsonPath('meta.idempotent_replay', false);
    expect(EmailMessage::query()->orderBy('id')->get()->map->only(['to_email', 'to_name', 'source', 'api_key_id', 'tags', 'metadata'])->all())->toBe([
        ['to_email' => 'jane@example.com', 'to_name' => 'Jane Doe', 'source' => MessageSource::Api, 'api_key_id' => $apiKey->id, 'tags' => ['receipt'], 'metadata' => ['order_id' => '1042']],
        ['to_email' => 'sam@example.com', 'to_name' => null, 'source' => MessageSource::Api, 'api_key_id' => $apiKey->id, 'tags' => ['receipt'], 'metadata' => ['order_id' => '1042']],
    ]);
    Queue::assertPushed(SendEmailMessage::class, 2);
});

it('sends through the existing pipeline until the provider accepts it', function () {
    $driver = FakeMailProviderDriver::install();
    [, $plaintext] = issueApiKey();

    $id = $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())->json('data.0.id');

    expect($driver->sent)->toHaveCount(1);
    $this->withToken($plaintext)->getJson("/api/v1/emails/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', MessageStatus::Accepted->value)
        ->assertJsonPath('data.to.email', 'jane@example.com');
});

it('refuses a sender the key is not allowed to use', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();
    SenderIdentity::factory()->create(['email' => 'ceo@acme.test']);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['from' => 'ceo@acme.test']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'sender_not_allowed');

    Queue::assertNothingPushed();
});

it('refuses marketing email without the marketing scope', function () {
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['purpose' => EmailPurpose::Marketing->value]))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_scope');
});

it('applies consent rules to marketing email from a key with the marketing scope', function () {
    [, $plaintext] = issueApiKey(['scopes' => ['emails:send', 'emails:send_marketing']]);
    Contact::factory()->create(['email' => 'jane@example.com']);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['purpose' => EmailPurpose::Marketing->value]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'recipient_not_eligible');
});

it('refuses a suppressed recipient with the reason', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey();
    Suppression::factory()->create(['email' => 'jane@example.com', 'reason' => SuppressionReason::HardBounce]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'recipient_not_eligible')
        ->assertJsonPath('error.fields.to.0', 'These recipients cannot receive this email: jane@example.com (suppressed (Hard bounce)).');

    Queue::assertNothingPushed();
});

it('validates the request shape with field errors', function () {
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', ['to' => [['email' => 'not-an-email']]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors(['from', 'purpose', 'to.0.email', 'subject', 'html'], 'error.fields');
});

it('renders a template with per-send variables', function () {
    $driver = FakeMailProviderDriver::install();
    [, $plaintext] = issueApiKey();
    $template = EmailTemplate::factory()->transactional()->create([
        'subject' => 'Order {{ order_number }}',
        'body' => '<p>Track it at <a href="{{ action_url }}">your order page</a>.</p>',
        'required_variables' => ['order_number', 'action_url'],
    ]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'subject' => null,
        'html' => null,
        'template_id' => $template->id,
        'variables' => ['order_number' => 'A-1042', 'action_url' => 'https://acme.test/orders/1042'],
    ]))->assertAccepted();

    $email = $driver->sent[0]->getOriginalMessage();
    expect($email->getSubject())->toBe('Order A-1042')
        ->and($email->getHtmlBody())->toContain('href="https://acme.test/orders/1042"');
    expect(EmailMessage::query()->sole()->getRawOriginal('variables'))->not->toContain('A-1042');
});

it('refuses a template send missing a required variable', function () {
    [, $plaintext] = issueApiKey();
    $template = EmailTemplate::factory()->transactional()->create(['body' => '<p>{{ action_url }}</p>', 'required_variables' => ['action_url']]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['subject' => null, 'html' => null, 'template_id' => $template->id]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'template_variables_missing');
});

it('records but never sends email from a test key', function () {
    $driver = FakeMailProviderDriver::install();
    [, $plaintext] = issueApiKey(['mode' => ApiKeyMode::Test]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())
        ->assertAccepted()
        ->assertJsonPath('data.0.status', MessageStatus::Simulated->value)
        ->assertJsonPath('meta.test_mode', true);

    expect($driver->sent)->toBeEmpty();
    expect(EmailMessage::query()->sole()->status)->toBe(MessageStatus::Simulated);
});

it('enforces the daily send limit per key', function () {
    Queue::fake([SendEmailMessage::class]);
    [, $plaintext] = issueApiKey(['daily_send_limit' => 2]);
    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['to' => [['email' => 'a@example.com'], ['email' => 'b@example.com']]]))->assertAccepted();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['to' => [['email' => 'c@example.com']]]))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'daily_send_limit_reached');

    Queue::assertPushed(SendEmailMessage::class, 2);
});

it('stores base64 attachments and attaches them when sending', function () {
    Storage::fake('local');
    $driver = FakeMailProviderDriver::install();
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'attachments' => [['filename' => 'Invoice 1042.pdf', 'content' => base64_encode('%PDF-1.4 test'), 'content_type' => 'application/pdf']],
    ]))->assertAccepted();

    $attachments = $driver->sent[0]->getOriginalMessage()->getAttachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getFilename())->toBe('Invoice 1042.pdf');
});

it('refuses an attachment type outside the allowed list', function () {
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'attachments' => [['filename' => 'run.exe', 'content' => base64_encode('MZ'), 'content_type' => 'application/x-msdownload']],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['attachments.0.content_type'], 'error.fields');
});

it('shows API clients that a sandbox captured their email instead of delivering it', function () {
    FakeMailProviderDriver::install();
    [, $plaintext] = issueApiKey(senderIdentity: SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrapSandbox())->create(['email' => 'billing@acme.test']));

    $id = $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())->assertAccepted()->json('data.0.id');

    $this->withToken($plaintext)->getJson("/api/v1/emails/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', MessageStatus::Captured->value)
        ->assertJsonPath('data.delivered_at', null);
    $this->withToken($plaintext)->getJson('/api/v1/emails?status=captured')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses to queue API email for a sandbox sender where sandboxes are turned off', function () {
    Queue::fake([SendEmailMessage::class]);
    config(['prosend.providers.allow_sandbox' => false]);
    [, $plaintext] = issueApiKey(senderIdentity: SenderIdentity::factory()->for(SmtpProvider::factory()->mailtrapSandbox())->create(['email' => 'billing@acme.test']));

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    Queue::assertNothingPushed();
});

it('counts CC and BCC addresses toward the daily send limit', function () {
    Queue::fake([SendEmailMessage::class]);
    [$apiKey, $plaintext] = issueApiKey(['daily_send_limit' => 3]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['cc' => ['sam@example.com'], 'bcc' => ['kim@example.com', 'lee@example.com']]))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'daily_send_limit_reached');

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload(['cc' => ['sam@example.com'], 'bcc' => ['kim@example.com']]))->assertAccepted();

    expect($apiKey->emailsQueuedToday())->toBe(3);
});

it('gives the daily allowance back when a send is refused', function () {
    Queue::fake([SendEmailMessage::class]);
    [$apiKey, $plaintext] = issueApiKey(['daily_send_limit' => 1]);
    Suppression::factory()->create(['email' => 'jane@example.com', 'reason' => SuppressionReason::HardBounce]);

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload())->assertUnprocessable();

    expect($apiKey->emailsQueuedToday())->toBe(0);
});

it('refuses an attachment whose content is not the declared type', function () {
    Queue::fake([SendEmailMessage::class]);
    Storage::fake('local');
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'attachments' => [
            ['filename' => 'terms.pdf', 'content' => base64_encode('%PDF-1.4 terms'), 'content_type' => 'application/pdf'],
            ['filename' => 'invoice.pdf', 'content' => base64_encode("MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff"), 'content_type' => 'application/pdf'],
        ],
    ]))->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors(['attachments.1.content'], 'error.fields');

    expect(Storage::disk('local')->allFiles('email-attachments'))->toBeEmpty();
    Queue::assertNothingPushed();
});

it('names an attachment with an extension of its real type', function () {
    Queue::fake([SendEmailMessage::class]);
    Storage::fake('local');
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'attachments' => [['filename' => 'setup.exe', 'content' => base64_encode('%PDF-1.4 receipt'), 'content_type' => 'application/pdf']],
    ]))->assertAccepted();

    $attachment = EmailMessage::query()->sole()->attachments[0];
    expect($attachment['name'])->toBe('setup.exe.pdf')
        ->and($attachment['path'])->toEndWith('.pdf');
});

it('refuses an attachment name with control characters instead of failing', function () {
    Queue::fake([SendEmailMessage::class]);
    Storage::fake('local');
    [, $plaintext] = issueApiKey();

    $this->withToken($plaintext)->postJson('/api/v1/emails', sendPayload([
        'attachments' => [['filename' => "invoice\r\n.pdf", 'content' => base64_encode('%PDF-1.4 receipt'), 'content_type' => 'application/pdf']],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['attachments.0.filename'], 'error.fields');
});
