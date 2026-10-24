<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasLabel
{
    case Brevo = 'brevo';
    case Smtp = 'smtp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Brevo => 'Brevo',
            self::Smtp => 'Generic SMTP',
        };
    }

    public function defaultHost(): ?string
    {
        return match ($this) {
            self::Brevo => 'smtp-relay.brevo.com',
            self::Smtp => null,
        };
    }
}
