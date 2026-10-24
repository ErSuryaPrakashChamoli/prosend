<?php

use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\Suppression;

it('returns 403 for an unsigned unsubscribe link', function () {
    $message = EmailMessage::factory()->create(['purpose' => EmailPurpose::Marketing]);

    $this->get('/unsubscribe/'.$message->uuid)->assertForbidden();
    $this->post('/unsubscribe/'.$message->uuid)->assertForbidden();

    expect(Suppression::query()->count())->toBe(0);
});

it('asks for confirmation and escapes the sender name', function () {
    $message = EmailMessage::factory()
        ->for(SenderIdentity::factory()->state(['name' => '<script>alert(1)</script> News']))
        ->create(['purpose' => EmailPurpose::Marketing, 'to_email' => 'jane@example.com']);

    $this->get($message->unsubscribeUrl())
        ->assertOk()
        ->assertSee('jane@example.com')
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);

    expect(Suppression::query()->count())->toBe(0);
});

it('unsubscribes the recipient with a one-click request', function () {
    $contact = Contact::factory()->consented()->create(['email' => 'jane@example.com']);
    $message = EmailMessage::factory()->for($contact)->create(['purpose' => EmailPurpose::Marketing, 'to_email' => 'jane@example.com']);

    $this->post($message->unsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertSee('unsubscribed');

    expect($contact->refresh()->unsubscribed_at)->not->toBeNull();
    expect(Suppression::query()->sole())
        ->email->toBe('jane@example.com')
        ->reason->toBe(SuppressionReason::Unsubscribed);
    expect($message->events()->where('type', EmailEventType::Unsubscribed)->count())->toBe(1);
});

it('does not record the unsubscribe twice', function () {
    $message = EmailMessage::factory()->create(['purpose' => EmailPurpose::Marketing]);

    $this->post($message->unsubscribeUrl())->assertOk();
    $this->post($message->unsubscribeUrl())->assertOk();

    expect($message->events()->where('type', EmailEventType::Unsubscribed)->count())->toBe(1);
});
