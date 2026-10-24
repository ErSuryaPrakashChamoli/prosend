<?php

use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use App\Filament\Pages\ComposeEmail;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('queues the composed email', function () {
    Queue::fake([SendEmailMessage::class]);
    $this->actingAs(User::factory()->sender()->create());
    $sender = SenderIdentity::factory()->create();

    Livewire::test(ComposeEmail::class)
        ->fillForm([
            'to' => ['jane@example.com'],
            'purpose' => EmailPurpose::Transactional->value,
            'sender_identity_id' => $sender->id,
            'subject' => 'Your invoice',
            'body' => '<p>Attached.</p>',
        ])
        ->call('send')
        ->assertHasNoFormErrors()
        ->assertNotified('Email queued');

    expect(EmailMessage::query()->sole())
        ->to_email->toBe('jane@example.com')
        ->subject->toBe('Your invoice');
    Queue::assertPushed(SendEmailMessage::class, 1);
});

it('shows why a suppressed recipient cannot be emailed', function () {
    Queue::fake([SendEmailMessage::class]);
    $this->actingAs(User::factory()->sender()->create());
    Suppression::factory()->create(['email' => 'spam@example.com', 'reason' => SuppressionReason::Complaint]);

    $component = Livewire::test(ComposeEmail::class)
        ->fillForm([
            'to' => ['spam@example.com'],
            'purpose' => EmailPurpose::Transactional->value,
            'sender_identity_id' => SenderIdentity::factory()->create()->id,
            'subject' => 'Hello',
            'body' => '<p>Hi</p>',
        ])
        ->call('send');

    expect($component->errors()->get('data.to'))->toBe(['These recipients cannot receive this email: spam@example.com (suppressed (Spam complaint)).']);

    expect(EmailMessage::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailMessage::class);
});

it('prefills the recipient from the link', function () {
    $this->actingAs(User::factory()->sender()->create());

    $this->get(ComposeEmail::getUrl(['to' => 'jane@example.com']))
        ->assertOk()
        ->assertSee('jane@example.com');
});
