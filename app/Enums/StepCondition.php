<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * When a follow-up step is sent. A reply, unsubscribe, bounce or complaint always ends the sequence first, so every
 * condition also means "and no reply". Opens are approximate: privacy features open some emails automatically and
 * block tracking in others.
 */
enum StepCondition: string implements HasDescription, HasLabel
{
    case NoReply = 'no_reply';
    case NotOpened = 'not_opened';
    case Opened = 'opened';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoReply => 'If there is no reply',
            self::NotOpened => 'If no earlier email was opened',
            self::Opened => 'If an earlier email was opened',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::NoReply => 'The usual follow-up.',
            self::NotOpened => 'For example, a resend with a new subject. Opens are approximate.',
            self::Opened => 'For example, a nudge to people who read but did not answer. Opens are approximate.',
        };
    }

    /**
     * Whether the step goes out, given whether an earlier email in the sequence was opened.
     */
    public function allows(bool $wasOpened): bool
    {
        return match ($this) {
            self::NoReply => true,
            self::NotOpened => ! $wasOpened,
            self::Opened => $wasOpened,
        };
    }
}
