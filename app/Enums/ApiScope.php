<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ApiScope: string implements HasDescription, HasLabel
{
    case EmailsSend = 'emails:send';
    case EmailsSendMarketing = 'emails:send_marketing';
    case EmailsRead = 'emails:read';
    case EmailsReadAll = 'emails:read_all';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EmailsSend => 'Send transactional email.',
            self::EmailsSendMarketing => 'Send marketing email. Consent, unsubscribe and suppression rules still apply.',
            self::EmailsRead => 'Read emails this key sent.',
            self::EmailsReadAll => 'Read every email, including those sent from the panel and by other keys.',
        };
    }
}
