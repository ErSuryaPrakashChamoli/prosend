<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ConsentStatus: string implements HasColor, HasDescription, HasLabel
{
    case Granted = 'granted';
    case Unknown = 'unknown';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Granted => 'Consent granted',
            self::Unknown => 'Unknown',
            self::Revoked => 'Consent revoked',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Granted => 'The contact opted in. Eligible for marketing email.',
            self::Unknown => 'No recorded consent. Transactional email only.',
            self::Revoked => 'The contact withdrew consent. Transactional email only.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Granted => 'success',
            self::Unknown => 'gray',
            self::Revoked => 'warning',
        };
    }
}
