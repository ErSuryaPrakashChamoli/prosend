<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WarmupStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Awaiting approval',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'primary',
            self::Paused => 'warning',
            self::Completed => 'success',
        };
    }

    /**
     * Statuses in which the plan limits sending. A completed plan keeps holding volume at its target,
     * sending hours, and rate; delete the plan to remove its limits.
     *
     * @return list<self>
     */
    public static function enforcing(): array
    {
        return [self::Active, self::Paused, self::Completed];
    }
}
