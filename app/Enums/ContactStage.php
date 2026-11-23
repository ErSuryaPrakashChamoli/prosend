<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a contact is in the outreach pipeline. Unsubscribed and Do Not Contact are not stages: they are the
 * contact's unsubscribe and the suppression list, which the sending engine enforces.
 */
enum ContactStage: string implements HasColor, HasDescription, HasLabel
{
    case New = 'new';
    case Active = 'active';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case Interested = 'interested';
    case Converted = 'converted';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Active => 'Active',
            self::Contacted => 'Contacted',
            self::Replied => 'Replied',
            self::Interested => 'Interested',
            self::Converted => 'Converted',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::New => 'Added, not yet worked on.',
            self::Active => 'Qualified and being worked on, not yet emailed.',
            self::Contacted => 'Emailed. Set automatically when a provider accepts a campaign or Compose email.',
            self::Replied => 'Wrote back. Set by hand: ProSend cannot read replies.',
            self::Interested => 'Showed interest.',
            self::Converted => 'Became a customer.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Active, self::Contacted => 'info',
            self::Replied, self::Interested => 'warning',
            self::Converted => 'success',
        };
    }

    /**
     * Stages that move to Contacted when an outreach email is accepted.
     *
     * @return list<self>
     */
    public static function notYetContacted(): array
    {
        return [self::New, self::Active];
    }
}
