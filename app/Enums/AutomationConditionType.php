<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AutomationConditionType: string implements HasLabel
{
    case StageIs = 'stage_is';
    case HasTag = 'has_tag';
    case IndustryIs = 'industry_is';
    case CampaignIs = 'campaign_is';
    case ConsentGranted = 'consent_granted';
    case HasOwner = 'has_owner';

    public function getLabel(): string
    {
        return match ($this) {
            self::StageIs => 'Prospect stage is',
            self::HasTag => 'Prospect has the tag',
            self::IndustryIs => 'Prospect industry is',
            self::CampaignIs => 'It happened in the campaign',
            self::ConsentGranted => 'Prospect consented to marketing',
            self::HasOwner => 'Prospect has an owner',
        };
    }
}
