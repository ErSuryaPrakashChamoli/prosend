<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailEventType: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Deferred = 'deferred';
    case Delivered = 'delivered';
    case SoftBounced = 'soft_bounced';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Unsubscribed = 'unsubscribed';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Accepted => 'Accepted by provider',
            self::Deferred => 'Deferred',
            self::Delivered => 'Delivered',
            self::SoftBounced => 'Soft bounce',
            self::Bounced => 'Hard bounce',
            self::Complained => 'Spam complaint',
            self::Unsubscribed => 'Unsubscribed',
            self::Opened => 'Opened',
            self::Clicked => 'Clicked',
            self::Failed => 'Failed',
            self::Ambiguous => 'Outcome unknown',
            self::Suppressed => 'Skipped (suppressed)',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Sending, self::Opened, self::Clicked => 'info',
            self::Accepted => 'primary',
            self::Delivered => 'success',
            self::Deferred, self::SoftBounced, self::Ambiguous => 'warning',
            self::Bounced, self::Complained, self::Failed => 'danger',
            self::Unsubscribed, self::Suppressed, self::Cancelled => 'gray',
        };
    }
}
