<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sending = 'sending';
    case Paused = 'paused';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::PartiallyFailed => 'Partially failed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'gray',
            self::Scheduled, self::Queued => 'info',
            self::Sending => 'primary',
            self::Paused, self::PartiallyFailed => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function canBeEdited(): bool
    {
        return $this === self::Draft;
    }

    public function canBePaused(): bool
    {
        return in_array($this, [self::Scheduled, self::Queued, self::Sending], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Scheduled, self::Queued, self::Sending, self::Paused], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::PartiallyFailed, self::Failed, self::Cancelled], true);
    }
}
