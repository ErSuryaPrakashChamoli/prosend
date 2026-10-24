<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Sender = 'sender';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Sender => 'Sender',
            self::Viewer => 'Viewer',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Full access, including providers, users, and audit logs.',
            self::Sender => 'Manages contacts and lists, composes emails, and runs campaigns.',
            self::Viewer => 'Read-only access to the dashboard, activity, and audiences.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Sender => 'primary',
            self::Viewer => 'gray',
        };
    }

    public function canSendEmail(): bool
    {
        return in_array($this, [self::Admin, self::Sender], true);
    }

    public function canManageAudiences(): bool
    {
        return in_array($this, [self::Admin, self::Sender], true);
    }

    public function canManageInfrastructure(): bool
    {
        return $this === self::Admin;
    }
}
