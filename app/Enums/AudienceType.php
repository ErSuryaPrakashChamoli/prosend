<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AudienceType: string implements HasLabel
{
    case Lists = 'lists';
    case Segment = 'segment';
    case SelectedContacts = 'selected_contacts';
    case AllContacts = 'all_contacts';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lists => 'One or more lists',
            self::Segment => 'A segment',
            self::SelectedContacts => 'Selected contacts',
            self::AllContacts => 'All contacts',
        };
    }
}
