<?php

use App\Logging\RedactApiKeys;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;

it('masks API keys in log messages and context before they are written', function () {
    $handler = new TestHandler;
    $logger = new Logger(new Monolog('test', [$handler]));
    (new RedactApiKeys)($logger);
    $key = 'psk_live_AAAAAAAAAAAA_'.str_repeat('b', 40);

    $logger->error("Request failed for {$key}", ['headers' => ['authorization' => "Bearer {$key}"]]);

    $record = $handler->getRecords()[0];
    expect($record->message)->toBe('Request failed for psk_live_[redacted]')
        ->and($record->context['headers']['authorization'])->toBe('Bearer psk_live_[redacted]');
});

it('applies to the application log channels', function () {
    expect(config('logging.channels.stack.tap'))->toContain(RedactApiKeys::class)
        ->and(config('logging.channels.single.tap'))->toContain(RedactApiKeys::class)
        ->and(config('logging.channels.daily.tap'))->toContain(RedactApiKeys::class);
});
