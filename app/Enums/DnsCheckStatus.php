<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DnsCheckStatus: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Found = 'found';
    case Incomplete = 'incomplete';
    case Missing = 'missing';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Found => 'Record found',
            self::Incomplete => 'Needs changes',
            self::Missing => 'Missing',
            self::Invalid => 'Invalid',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Found => 'success',
            self::Incomplete => 'warning',
            self::Missing, self::Invalid => 'danger',
        };
    }
}
