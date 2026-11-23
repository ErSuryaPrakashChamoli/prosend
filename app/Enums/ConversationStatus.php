<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a conversation with a prospect stands. Interested and Converted count as positive replies.
 */
enum ConversationStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Open = 'open';
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case FollowUp = 'follow_up';
    case Converted = 'converted';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Open => 'Open',
            self::Interested => 'Interested',
            self::NotInterested => 'Not interested',
            self::FollowUp => 'Follow-up',
            self::Converted => 'Converted',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Open, self::FollowUp => 'warning',
            self::Interested, self::Converted => 'success',
            self::NotInterested, self::Closed => 'gray',
        };
    }

    public function isPositive(): bool
    {
        return in_array($this, [self::Interested, self::Converted], true);
    }

    /**
     * The prospect stage this status implies, when it implies one.
     */
    public function contactStage(): ?ContactStage
    {
        return match ($this) {
            self::Interested => ContactStage::Interested,
            self::Converted => ContactStage::Converted,
            default => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function positive(): array
    {
        return [self::Interested, self::Converted];
    }
}
