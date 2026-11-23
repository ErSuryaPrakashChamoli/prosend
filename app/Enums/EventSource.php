<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventSource: string implements HasLabel
{
    case Application = 'application';
    case BrevoWebhook = 'brevo_webhook';
    case Recipient = 'recipient';
    case Tracking = 'tracking';
    case Inbox = 'inbox';

    public function getLabel(): string
    {
        return match ($this) {
            self::Application => 'ProSend',
            self::BrevoWebhook => 'Brevo webhook',
            self::Recipient => 'Recipient',
            self::Tracking => 'ProSend tracking',
            self::Inbox => 'Inbox (bounce report)',
        };
    }
}
