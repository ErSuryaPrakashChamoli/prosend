<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProviderHealth: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Failing = 'failing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Healthy => 'Healthy',
            self::Failing => 'Failing',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Healthy => 'success',
            self::Failing => 'danger',
        };
    }
}
