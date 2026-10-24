<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SuppressionReason: string implements HasColor, HasLabel
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case InvalidAddress = 'invalid_address';
    case Unsubscribed = 'unsubscribed';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::HardBounce => 'Hard bounce',
            self::Complaint => 'Spam complaint',
            self::InvalidAddress => 'Invalid address',
            self::Unsubscribed => 'Unsubscribed',
            self::Manual => 'Added manually',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HardBounce, self::InvalidAddress => 'danger',
            self::Complaint => 'warning',
            self::Unsubscribed => 'gray',
            self::Manual => 'info',
        };
    }

    /**
     * Unsubscribing stops marketing email only; every other reason stops all email to the address.
     */
    public function blocksTransactional(): bool
    {
        return $this !== self::Unsubscribed;
    }

    /**
     * @return list<self>
     */
    public static function blocking(EmailPurpose $purpose): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason): bool => $purpose === EmailPurpose::Marketing || $reason->blocksTransactional(),
        ));
    }
}
