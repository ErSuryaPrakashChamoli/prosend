<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum EmailPurpose: string implements HasColor, HasDescription, HasLabel
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Transactional => 'Transactional',
            self::Marketing => 'Marketing',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Transactional => 'Account, order, or service messages the recipient expects. No consent needed, but bounced and complained addresses are blocked.',
            self::Marketing => 'Newsletters and promotions. Only sent to contacts with recorded consent who have not unsubscribed, and always include an unsubscribe link.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Transactional => 'info',
            self::Marketing => 'primary',
        };
    }
}
