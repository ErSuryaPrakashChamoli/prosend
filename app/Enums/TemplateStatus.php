<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum TemplateStatus: string implements HasColor, HasDescription, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Draft => 'Being written. Not offered when composing or creating campaigns.',
            self::Active => 'Ready to use in campaigns and composed emails.',
            self::Archived => 'Kept for reference. Not offered for new emails.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Archived => 'warning',
        };
    }
}
