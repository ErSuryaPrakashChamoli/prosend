<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BounceType: string implements HasLabel
{
    case Hard = 'hard';
    case Soft = 'soft';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hard => 'Hard bounce',
            self::Soft => 'Soft bounce',
        };
    }
}
