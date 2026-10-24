<?php

namespace App\Services\Sending;

use App\Enums\SendFailureKind;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

final readonly class SendFailure
{
    /**
     * SMTP replies that mean the provider rejected our credentials rather than the message.
     */
    private const AUTHENTICATION_CODES = [530, 534, 535];

    public function __construct(
        public SendFailureKind $kind,
        public string $summary,
        public ?string $code = null,
        public bool $retryable = false,
    ) {}

    /**
     * @param  list<string>  $secrets  Values to redact from the summary, such as the SMTP username.
     */
    public static function fromException(Throwable $exception, array $secrets = []): self
    {
        $summary = self::redact($exception->getMessage(), $secrets);

        if ($exception instanceof RfcComplianceException) {
            return new self(SendFailureKind::Permanent, "Invalid address: {$summary}");
        }

        $code = (int) $exception->getCode();

        if (in_array($code, self::AUTHENTICATION_CODES, true)) {
            return new self(SendFailureKind::Transient, "The provider rejected the SMTP credentials. {$summary}", (string) $code, retryable: true);
        }

        if ($code >= 500 && $code < 600) {
            return new self(SendFailureKind::Permanent, $summary, (string) $code, retryable: str_contains($summary, '5.7.'));
        }

        if ($code >= 400 && $code < 500) {
            return new self(SendFailureKind::Transient, $summary, (string) $code, retryable: true);
        }

        // Without an SMTP reply the connection itself failed. Once DATA was sent, the provider may already have the message.
        if ($exception instanceof TransportExceptionInterface && str_contains($exception->getDebug(), '> DATA')) {
            return new self(SendFailureKind::Ambiguous, "The connection failed while the message was being submitted. {$summary}");
        }

        return new self(SendFailureKind::Transient, $summary, retryable: true);
    }

    /**
     * Whether the failure points at the provider connection rather than the individual message.
     */
    public function affectsProvider(): bool
    {
        return $this->code === null || in_array((int) $this->code, self::AUTHENTICATION_CODES, true);
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function redact(string $message, array $secrets): string
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));

        return $secrets === [] ? $message : str_replace($secrets, '[redacted]', $message);
    }
}
