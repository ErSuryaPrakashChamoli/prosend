<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum MessageStatus: string implements HasColor, HasDescription, HasLabel
{
    case Queued = 'queued';
    case Deferred = 'deferred';
    case Sending = 'sending';
    case Unknown = 'unknown';
    case Accepted = 'accepted';
    case SoftBounced = 'soft_bounced';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';
    case Simulated = 'simulated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Deferred => 'Retrying',
            self::Sending => 'Sending',
            self::Unknown => 'Status unknown',
            self::Accepted => 'Accepted by provider',
            self::SoftBounced => 'Soft bounce',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Bounced => 'Hard bounce',
            self::Complained => 'Spam complaint',
            self::Suppressed => 'Skipped (suppressed)',
            self::Cancelled => 'Cancelled',
            self::Simulated => 'Test (not sent)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Queued => 'Waiting for a queue worker.',
            self::Deferred => 'A temporary error occurred. Another attempt is scheduled.',
            self::Sending => 'Being handed to the provider right now.',
            self::Unknown => 'The connection failed after the message may have been accepted. It is not retried automatically, to avoid sending a duplicate.',
            self::Accepted => 'The provider accepted the message. This does not confirm it reached the inbox.',
            self::SoftBounced => 'The receiving server temporarily refused the message. The provider may retry.',
            self::Delivered => 'The provider reported that the receiving server accepted the message.',
            self::Failed => 'The message could not be sent.',
            self::Bounced => 'The receiving server permanently rejected the address. It is now suppressed.',
            self::Complained => 'The recipient marked the message as spam. The address is now suppressed.',
            self::Suppressed => 'Not sent because the address is on the suppression list or has not consented.',
            self::Cancelled => 'Not sent because the email or campaign was cancelled.',
            self::Simulated => 'Created with a test API key. It was validated and recorded but never sent.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'info',
            self::Deferred, self::Unknown, self::SoftBounced => 'warning',
            self::Accepted => 'primary',
            self::Delivered => 'success',
            self::Failed, self::Bounced, self::Complained => 'danger',
            self::Suppressed, self::Cancelled, self::Simulated => 'gray',
        };
    }

    public function isAwaitingSend(): bool
    {
        return in_array($this, [self::Queued, self::Deferred], true);
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Queued, self::Deferred, self::Sending];
    }

    /**
     * Statuses for messages the provider accepted, whatever happened afterwards.
     *
     * @return list<self>
     */
    public static function acceptedByProvider(): array
    {
        return [self::Accepted, self::SoftBounced, self::Delivered, self::Bounced, self::Complained];
    }

    /**
     * Provider events may arrive out of order, so a later event only replaces a status that it outranks.
     */
    public function canBeReplacedBy(self $next): bool
    {
        if (in_array($this, [self::Suppressed, self::Cancelled, self::Simulated], true)) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Queued, self::Suppressed, self::Cancelled, self::Simulated => 0,
            self::Deferred, self::Sending => 1,
            self::Unknown => 2,
            self::Accepted => 3,
            self::SoftBounced => 4,
            self::Delivered => 5,
            self::Failed, self::Bounced => 6,
            self::Complained => 7,
        };
    }
}
