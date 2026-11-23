<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MessageSource: string implements HasLabel
{
    case Compose = 'compose';
    case Campaign = 'campaign';
    case Api = 'api';
    case Test = 'test';
    case Inbox = 'inbox';
    case Automation = 'automation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compose => 'Compose',
            self::Campaign => 'Campaign',
            self::Api => 'API',
            self::Test => 'Test email',
            self::Inbox => 'Inbox reply',
            self::Automation => 'Automation',
        };
    }
}
