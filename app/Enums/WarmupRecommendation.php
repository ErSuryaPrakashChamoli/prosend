<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum WarmupRecommendation: string implements HasColor, HasDescription, HasLabel
{
    case Continue = 'continue';
    case HoldVolume = 'hold_volume';
    case ReduceVolume = 'reduce_volume';
    case Pause = 'pause';
    case ReviewAuthentication = 'review_authentication';
    case InvestigateBounces = 'investigate_bounces';
    case ReviewListQuality = 'review_list_quality';
    case ContactProvider = 'contact_provider';
    case NotEnoughData = 'not_enough_data';

    public function getLabel(): string
    {
        return match ($this) {
            self::Continue => 'Continue as planned',
            self::HoldVolume => 'Hold the current volume',
            self::ReduceVolume => 'Reduce sending volume',
            self::Pause => 'Pause sending',
            self::ReviewAuthentication => 'Review domain authentication',
            self::InvestigateBounces => 'Investigate the bounce spike',
            self::ReviewListQuality => 'Review list quality',
            self::ContactProvider => 'Contact the provider about throttling',
            self::NotEnoughData => 'Not enough data yet',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Continue => 'Bounces and complaints are within limits. Volume increases at the next step.',
            self::HoldVolume => 'Recent volume was well below the current limit, so there is no evidence the next step is safe yet.',
            self::ReduceVolume => 'Signals are close to the limits. Send less until they improve.',
            self::Pause => 'Bounce or complaint rates are above the plan limits. Sending under this plan has stopped.',
            self::ReviewAuthentication => 'SPF, DKIM, or DMARC is missing or the provider has not authenticated the domain. Fix this before increasing volume.',
            self::InvestigateBounces => 'Hard bounces are above the limit. Check where the affected addresses came from.',
            self::ReviewListQuality => 'Spam complaints are above the limit. Make sure everyone on the list asked to hear from you.',
            self::ContactProvider => 'Receiving servers or the provider are deferring a lot of email. Ask the provider whether the account is being throttled.',
            self::NotEnoughData => 'Too few emails were accepted in the last 7 days to judge reputation. Rates are not meaningful yet.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Continue => 'success',
            self::HoldVolume, self::NotEnoughData => 'gray',
            self::ReduceVolume, self::ReviewAuthentication, self::ContactProvider => 'warning',
            self::Pause, self::InvestigateBounces, self::ReviewListQuality => 'danger',
        };
    }
}
