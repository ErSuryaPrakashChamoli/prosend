<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ApiKeyMode: string implements HasColor, HasDescription, HasLabel
{
    case Live = 'live';
    case Test = 'test';

    public function getLabel(): string
    {
        return match ($this) {
            self::Live => 'Live',
            self::Test => 'Test',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Live => 'Requests send real email.',
            self::Test => 'Requests are validated and recorded exactly like live ones, but nothing is ever sent.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Live => 'success',
            self::Test => 'gray',
        };
    }
}
