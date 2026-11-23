<?php

namespace App\Logging;

use App\Models\ApiKey;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Masks anything that looks like a ProSend API key before a log record is written, wherever it appears:
 * in the message, in context values, or in exception messages added by the framework.
 */
class RedactApiKeys
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: ApiKey::redact($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        array_walk_recursive($values, function (mixed &$value): void {
            if (is_string($value)) {
                $value = ApiKey::redact($value);
            }
        });

        return $values;
    }
}
