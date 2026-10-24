<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * An expected API error with a stable code. Rendered as the standard error envelope and never reported.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $code, string $message): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'The requested resource does not exist, or this key cannot see it.');
    }
}
