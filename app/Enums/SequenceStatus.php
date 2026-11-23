<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where one prospect is in a campaign's sequence.
 */
enum SequenceStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Completed = 'completed';
    case Replied = 'replied';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'In sequence',
            self::Completed => 'Finished',
            self::Replied => 'Replied',
            self::Bounced => 'Bounced',
            self::Unsubscribed => 'Unsubscribed',
            self::Failed => 'Failed',
            self::Stopped => 'Stopped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'primary',
            self::Completed => 'gray',
            self::Replied => 'success',
            self::Bounced, self::Failed => 'danger',
            self::Unsubscribed, self::Stopped => 'warning',
        };
    }
}
