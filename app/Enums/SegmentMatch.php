<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SegmentMatch: string implements HasLabel
{
    case All = 'all';
    case Any = 'any';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Contacts matching all rules',
            self::Any => 'Contacts matching any rule',
        };
    }
}
