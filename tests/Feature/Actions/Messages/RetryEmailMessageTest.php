<?php

use App\Actions\Messages\RetryEmailMessage;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('cannot retry an email whose content was removed under the retention policy', function () {
    Queue::fake([SendEmailMessage::class]);
    $message = EmailMessage::factory()->status(MessageStatus::Unknown)->create(['html_body' => null]);

    expect(RetryEmailMessage::canRetry($message))->toBeFalse();
    expect(fn () => app(RetryEmailMessage::class)->handle($message))->toThrow(ValidationException::class, 'retention policy');

    Queue::assertNothingPushed();
});

it('still retries a campaign email, which is rendered from its campaign', function () {
    Queue::fake([SendEmailMessage::class]);
    $message = EmailMessage::factory()->for(Campaign::factory())->status(MessageStatus::Unknown)->create(['html_body' => null]);

    expect(RetryEmailMessage::canRetry($message))->toBeTrue();
});
