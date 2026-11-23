<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Agency-level access. Admin means Super Admin: every client, plus clients, roles and users. Everyone else is a member
 * whose abilities come from their role in each client. Sender and Viewer are kept from before clients existed; they
 * only decide which role a migrated account received.
 */
enum UserRole: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Sender = 'sender';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Super Admin',
            self::Sender, self::Viewer => 'Member',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Every client, plus managing clients, roles and users.',
            self::Sender, self::Viewer => 'Reaches the clients they are a member of, with the role given there.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Sender, self::Viewer => 'gray',
        };
    }
}
