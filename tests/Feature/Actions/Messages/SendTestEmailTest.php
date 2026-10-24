<?php

use App\Actions\Messages\SendTestEmail;
use App\Enums\EmailPurpose;
use App\Enums\MessageStatus;
use App\Models\SenderIdentity;
use App\Models\User;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Fakes\FakeMailProviderDriver;

it('sends a transactional test copy to the signed-in user straight away', function () {
    $driver = FakeMailProviderDriver::install();
    $user = User::factory()->sender()->create(['email' => 'me@acme.test']);

    $message = app(SendTestEmail::class)->handle([
        'sender_identity_id' => SenderIdentity::factory()->create()->id,
        'subject' => 'Launch news',
        'body' => '<p>Hello</p>',
    ], $user);

    expect($message)
        ->status->toBe(MessageStatus::Accepted)
        ->purpose->toBe(EmailPurpose::Transactional)
        ->to_email->toBe('me@acme.test')
        ->subject->toBe('[Test] Launch news');
    expect($driver->sent)->toHaveCount(1);
});

it('reports a temporary error as failed instead of leaving the test waiting', function () {
    FakeMailProviderDriver::install()->failWith(new UnexpectedResponseException('421 Try again later', 421));

    $message = app(SendTestEmail::class)->handle([
        'sender_identity_id' => SenderIdentity::factory()->create()->id,
        'subject' => 'Launch news',
        'body' => '<p>Hello</p>',
    ], User::factory()->create());

    expect($message)
        ->status->toBe(MessageStatus::Failed)
        ->error_message->toBe('421 Try again later');
});
